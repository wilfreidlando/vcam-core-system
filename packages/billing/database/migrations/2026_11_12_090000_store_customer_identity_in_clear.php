<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0123 — l'identité du client est conservée **en clair**.
 *
 * Jusqu'ici, `customers.email_ciphertext` et `customers.phone_ciphertext`
 * portaient l'adresse et le numéro, et `email_bidx` / `phone_bidx` leur
 * index aveugle (ADR-0083 §2). L'instantané figé sur la facture
 * (`invoices.customer_snapshot`) portait la même chose.
 *
 * Cette migration fait le passage **une fois** :
 *
 * 1. elle ajoute `email` et `phone` ;
 * 2. elle **lit** l'ancien format — avec la clé du déploiement
 *    (`PII_ENCRYPTION_KEYS`), le temps de la migration — et l'écrit en
 *    clair ;
 * 3. elle réécrit l'instantané de facture avec les mêmes valeurs, en clair ;
 * 4. elle **supprime** les colonnes chiffrées et les index aveugles, et
 *    pose les index sur les valeurs normalisées.
 *
 * Une installation qui n'a jamais chiffré de client passe sans clé. Une
 * installation qui a chiffré des clients **sans** clé échoue bruyamment :
 * la donnée ne peut pas être lue, elle ne peut donc pas être écrite en
 * clair — et rien n'est perdu silencieusement.
 *
 * L'instantané d'une facture émise est figé (ADR-0073 §1) : le trigger
 * `invoice_issued_immutable` refuse toute modification de la colonne. La
 * migration ne change que l'**écodage** des valeurs, jamais leur contenu —
 * le trigger est donc suspendu le temps de la réécriture, puis remis.
 *
 * Le retour arrière est impossible : le Core ne garde plus de clé, et une
 * valeur en clair ne peut pas devenir chiffrée sans elle. `down()` le dit
 * plutôt que de perdre l'identité des clients en silence.
 */
return new class extends Migration
{
    /** @var array<string, string>|null `v<version>` => clé brute */
    private ?array $keys = null;

    /** @var array<string, Encrypter> */
    private array $encrypters = [];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
        });

        $this->carryOverCustomers();
        $this->carryOverSnapshots();

        Schema::table('customers', function (Blueprint $table): void {
            // Les index d'abord : une colonne supprimée emporte son index
            // avec elle, et la suppression explicite échouerait ensuite.
            // Ce sont des **index** (créés par un `CREATE UNIQUE INDEX`,
            // pas des contraintes) : c'est `dropIndex` qui les retire.
            $table->dropIndex('customers_project_email_unique');
            $table->dropIndex('customers_project_phone_idx');
            $table->dropColumn(['email_ciphertext', 'email_bidx', 'phone_ciphertext', 'phone_bidx']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            // L'unicité de l'adresse par projet est portée par la valeur
            // normalisée (ADR-0070 §4) : `Ali@Example.cm` et
            // `ali@example.cm` restent le même client (ADR-0123).
            $table->unique(['project_id', 'email'], 'customers_project_email_unique');
            $table->index(['project_id', 'phone'], 'customers_project_phone_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'ADR-0123 : le passage en clair est irréversible — le Core ne garde plus de clé '
            .'pour les données client. Pour revenir en arrière, restaurez une sauvegarde '
            .'antérieure à cette migration.',
        );
    }

    /**
     * Les clients enregistrés : la valeur chiffrée est lue, puis écrite en
     * clair.
     */
    private function carryOverCustomers(): void
    {
        $rows = [];

        foreach (DB::table('customers')
            ->select(['id', 'email_ciphertext', 'phone_ciphertext'])
            ->whereNotNull('email_ciphertext')
            ->orWhereNotNull('phone_ciphertext')
            ->get() as $record) {
            $rows[] = (array) $record;
        }

        if ($rows === []) {
            // Personne n'a jamais été chiffré : la migration passe sans clé.
            return;
        }

        foreach ($rows as $row) {
            $email = $row['email_ciphertext'] ?? null;
            $phone = $row['phone_ciphertext'] ?? null;

            DB::table('customers')
                ->where('id', $row['id'])
                ->update([
                    'email' => is_string($email) ? $this->decrypt($email) : null,
                    'phone' => is_string($phone) ? $this->decrypt($phone) : null,
                ]);
        }
    }

    /**
     * L'instantané figé sur les factures : les mêmes valeurs, en clair.
     */
    private function carryOverSnapshots(): void
    {
        $rows = [];

        foreach (DB::table('invoices')
            ->select(['id', 'customer_snapshot'])
            ->whereNotNull('customer_snapshot')
            ->get() as $row) {
            $rows[] = (array) $row;
        }

        if (! $this->snapshotsAreEncrypted($rows)) {
            return;
        }

        DB::statement('ALTER TABLE invoices DISABLE TRIGGER invoice_issued_immutable');

        try {
            foreach ($rows as $row) {
                $snapshot = $this->snapshotOf($row);

                if ($snapshot === null) {
                    continue;
                }

                $changed = false;

                foreach (['display_name_ciphertext' => 'display_name', 'email_ciphertext' => 'email', 'phone_ciphertext' => 'phone'] as $old => $new) {
                    if (! array_key_exists($old, $snapshot)) {
                        continue;
                    }

                    $value = $snapshot[$old];
                    $snapshot[$new] = is_string($value) && $value !== '' ? $this->decrypt($value) : null;
                    unset($snapshot[$old]);
                    $changed = true;
                }

                if ($changed) {
                    DB::table('invoices')
                        ->where('id', $row['id'])
                        ->update([
                            'customer_snapshot' => json_encode(
                                $snapshot === [] ? new stdClass : $snapshot,
                                JSON_THROW_ON_ERROR,
                            ),
                        ]);
                }
            }
        } finally {
            DB::statement('ALTER TABLE invoices ENABLE TRIGGER invoice_issued_immutable');
        }
    }

    /**
     * L'instantané d'une facture, décodé — `null` quand il n'y en a pas.
     *
     * @param array<mixed, mixed> $row
     * @return array<mixed, mixed>|null
     */
    private function snapshotOf(array $row): ?array
    {
        $raw = $row['customer_snapshot'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $snapshot = json_decode($raw, true);

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param iterable<int, array<mixed, mixed>> $rows
     */
    private function snapshotsAreEncrypted(iterable $rows): bool
    {
        foreach ($rows as $row) {
            $snapshot = $this->snapshotOf($row);

            if ($snapshot === null) {
                continue;
            }

            foreach (['display_name_ciphertext', 'email_ciphertext', 'phone_ciphertext'] as $key) {
                if (isset($snapshot[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * La lecture de l'ancien format : `pii:v<version>:<payload>`.
     */
    private function decrypt(string $ciphertext): string
    {
        if (! str_starts_with($ciphertext, 'pii:')) {
            throw new RuntimeException('ADR-0123 : valeur chiffrée illisible — le préfixe « pii: » est absent.');
        }

        $rest = substr($ciphertext, 4);
        $colon = strpos($rest, ':');

        if ($colon === false) {
            throw new RuntimeException('ADR-0123 : valeur chiffrée illisible — la version de la clé est absente.');
        }

        $version = substr($rest, 0, $colon);
        $payload = substr($rest, $colon + 1);
        $key = $this->keys()[$version] ?? null;

        if ($key === null) {
            throw new RuntimeException(
                'ADR-0123 : la clé « '.$version.' » est absente de PII_ENCRYPTION_KEYS — '
                .'les clients déjà enregistrés ne peuvent pas être lus.',
            );
        }

        try {
            return ($this->encrypters[$version] ??= new Encrypter($key, 'aes-256-gcm'))->decryptString($payload);
        } catch (Throwable $e) {
            throw new RuntimeException('ADR-0123 : la valeur chiffrée est illisible ou altérée.', 0, $e);
        }
    }

    /**
     * Le trousseau du déploiement : `v1:base64:<clé>,v2:base64:<clé>`.
     *
     * @return array<string, string> `v<version>` => clé brute
     */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        // `Env::get()` et non le raccourci `env()` : la lecture de
        // l'environnement hors des fichiers de configuration est
        // discouraged — ici, elle est la seule possible : la clé n'existe
        // que le temps de cette migration.
        $configured = Env::get('PII_ENCRYPTION_KEYS', '');
        $configured = is_string($configured) ? $configured : '';
        $keys = [];

        foreach (array_filter(array_map('trim', explode(',', $configured)), static fn (string $entry): bool => $entry !== '') as $entry) {
            if (preg_match('/^v(\d{1,6}):base64:([A-Za-z0-9+\/=]+)$/', $entry, $matches) !== 1) {
                throw new RuntimeException('ADR-0123 : PII_ENCRYPTION_KEYS est illisible — « '.$entry.' ».');
            }

            $raw = base64_decode($matches[2], true);

            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException('ADR-0123 : la clé '.$matches[1].' doit décoder en 32 octets.');
            }

            $keys['v'.$matches[1]] = $raw;
        }

        if ($keys === []) {
            throw new RuntimeException(
                'ADR-0123 : PII_ENCRYPTION_KEYS est vide — il faut la clé du déploiement pour '
                .'lire les clients déjà enregistrés.',
            );
        }

        return $this->keys = $keys;
    }
};
