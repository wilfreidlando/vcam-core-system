<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * La console parle au lecteur, pas à l'ingénieur.
 *
 * Le client (`packages/portal/`) écrit déjà ainsi : « Vous gardez l'accès jusqu'à
 * la fin de la période payée », « Confirmez sur votre téléphone », « Format :
 * +237 6 XX XX XX XX ». La console, elle, affichait encore des décisions et du
 * texte technique : « la relance d'un client passe par son portail ou par votre
 * propre message (ADR-0098) », « Provider », « Wallet à créditer », « Portées ».
 *
 * Ce test est le garde-fou qui fait rester les écrans dans cette langue :
 *
 *  1. il extrait les **chaînes visibles** des gabarits Blade de la console — le
 *     texte entre les balises et les valeurs des attributs que l'être humain
 *     lit (`title`, `label`, `hint`, `placeholder`, `aria-label`, `alt`,
 *     `value`, `content`…) — en ignorant les commentaires Blade
 *     (`{{-- … --}}`), les blocs `@php … @endphp`, les expressions `{{ … }}` et
 *     les attributs techniques (`class`, `id`, `href`, `name`, `wire:*`, `x-*`,
 *     `data-*`) ;
 *  2. il **refuse** une liste courte et explicite d'expressions interdites : une
 *     référence d'ADR (`ADR-\d+`) et les termes de code employés comme
 *     étiquettes (`webhook`, `endpoint`, `provider`, `payload`, `meter`,
 *     `callback`, `portée`/`scope`, `idempotence`, `tenant`) ;
 *  3. il accepte, écran par écran, les endroits où le terme **est** l'objet que
 *     l'opérateur manipule (une clé, un en-tête, un nom de champ qu'il doit
 *     recopier), avec la raison écrite dans `ALLOWED` ;
 *  4. il tient une **ratchet** : les écrans pas encore réécrits sont listés
 *     explicitement, avec les termes qui leur restent. La liste rétrécit à
 *     mesure que le travail avance ; un écran que l'on a fini de réécrire et
 *     que l'on laisse dans la ratchet fait échouer le test.
 */
final class ConsoleScreensSpeakPlainFrenchTest extends TestCase
{
    /**
     * Les expressions que la console ne doit plus montrer.
     *
     * Chacune est un mot du code, pas un mot de l'activité : on parle d'un
     * *prestataire*, d'un *portefeuille*, d'une *offre*, de *droits accordés*,
     * de la *comptabilité* du projet.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        'ADR' => '/\bADR-\d+/',
        'webhook' => '/\bwebhooks?\b/i',
        'endpoint' => '/\bendpoints?\b/i',
        'provider' => '/\bproviders?\b/i',
        'payload' => '/\bpayloads?\b/i',
        'meter' => '/\bmeters?\b/i',
        'callback' => '/\bcallbacks?\b/i',
        'portée' => '/\bport[ée]es?\b/i',
        'scope' => '/\bscopes?\b/i',
        'idempotence' => '/\bidempoten\w*/i',
        'tenant' => '/\btenants?\b/i',
        // Les quatre mots de la même famille : le code, encore.
        'wallet' => '/\bwallets?\b/i',
        'plan' => '/\bplans?\b/i',
        'quota' => '/\bquotas?\b/i',
        'grand livre' => '/grand[\s-]?livre/i',
    ];

    /**
     * Les exceptions, écran par écran, avec la raison de l'exception.
     *
     * On n'autorise un terme que là où il **est** la chose que l'opérateur
     * manipule : une clé qu'il recopie, un en-tête qu'il doit transmettre tel
     * quel, un nom de champ du contrat public.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED = [
        'resources/views/admin/payments/show.blade.php' => [
            'idempotence' => 'le nom de l\'en-tête `Idempotency-Key` : c\'est la clé que l\'intégrateur recopie, et elle doit être recopiée telle quelle.',
        ],
        'resources/views/admin/notifications/show.blade.php' => [
            'idempotence' => 'le nom de l\'en-tête `Idempotency-Key` : c\'est la clé que l\'intégrateur recopie, et elle doit être recopiée telle quelle.',
        ],
        'resources/views/admin/webhook-events/index.blade.php' => [
            'provider' => 'le champ `provider_code` figure dans l\'événement que le destinataire reçoit : le mot est celui du contrat qu\'il lit.',
            'callback' => 'le champ `callback_url` figure dans la requête que l\'intégrateur envoie : le mot est celui du contrat qu\'il écrit.',
            'webhook' => 'l\'écran montre le catalogue des événements de la fonctionnalité elle-même : le mot nomme la chose que l\'opérateur a enregistrée.',
            'endpoint' => 'idem : le mot nomme l\'adresse que l\'opérateur a enregistrée pour son application.',
            'wallet' => 'les noms des événements (`wallet.credited`, `wallet.debited`) figurent dans le contrat que le destinataire reçoit : le mot est celui du contrat qu\'il lit.',
        ],
        'resources/views/admin/webhook-deliveries/dead.blade.php' => [
            'webhook' => 'l\'écran montre les livraisons de la fonctionnalité elle-même : le mot nomme la chose que l\'opérateur a enregistrée.',
        ],
        'resources/views/admin/webhook-deliveries/show.blade.php' => [
            'webhook' => 'l\'écran montre une livraison de la fonctionnalité elle-même : le mot nomme la chose que l\'opérateur a enregistrée.',
        ],
    ];

    /**
     * Les écrans réécrits : plus aucun terme interdit, plus aucune référence
     * d'ADR dans les mots que l'opérateur lit.
     *
     * @var list<string>
     */
    private const REWRITTEN = [
        'resources/views/admin/audit-log/_action.blade.php',
        'resources/views/admin/audit-log/_actor.blade.php',
        'resources/views/admin/audit-log/_resource.blade.php',
        'resources/views/admin/audit-log/index.blade.php',
        'resources/views/admin/auth/invitation.blade.php',
        'resources/views/admin/auth/login.blade.php',
        'resources/views/admin/auth/mfa-enroll.blade.php',
        'resources/views/admin/auth/mfa-verify.blade.php',
        'resources/views/admin/auth/step-up.blade.php',
        'resources/views/admin/billing/customers/form.blade.php',
        'resources/views/admin/billing/customers/index.blade.php',
        'resources/views/admin/billing/invoices/index.blade.php',
        'resources/views/admin/billing/partials/cursor-pager.blade.php',
        'resources/views/admin/channel-sessions/show.blade.php',
        'resources/views/admin/organizations/create.blade.php',
        'resources/views/admin/organizations/edit.blade.php',
        'resources/views/admin/organizations/index.blade.php',
        'resources/views/admin/operators/create.blade.php',
        'resources/views/admin/operators/edit.blade.php',
        'resources/views/admin/operators/index.blade.php',
        'resources/views/admin/payments/index.blade.php',
        'resources/views/admin/payments/partials/journey.blade.php',
        'resources/views/admin/projects/edit.blade.php',
        'resources/views/admin/projects/index.blade.php',
        'resources/views/components/admin/alert.blade.php',
        'resources/views/components/admin/confirm-dialog.blade.php',
        'resources/views/components/admin/curve.blade.php',
        'resources/views/components/admin/errors.blade.php',
        'resources/views/components/admin/field.blade.php',
        'resources/views/components/admin/meter.blade.php',
        'resources/views/components/admin/pagination.blade.php',
        'resources/views/components/admin/search-box.blade.php',
        'resources/views/components/admin/stat.blade.php',
        'resources/views/components/admin/status-badge.blade.php',
        'resources/views/components/admin/table.blade.php',
        'resources/views/layouts/admin.blade.php',
        'resources/views/admin/api-keys/create.blade.php',
        'resources/views/admin/billing/indicators.blade.php',
        'resources/views/admin/billing/invoices/form.blade.php',
        'resources/views/admin/billing/invoices/receipts.blade.php',
        'resources/views/admin/billing/invoices/show.blade.php',
        'resources/views/admin/billing/ledger.blade.php',
        'resources/views/admin/billing/plans/form.blade.php',
        'resources/views/admin/billing/plans/index.blade.php',
        'resources/views/admin/billing/portal.blade.php',
        'resources/views/admin/billing/receivables.blade.php',
        'resources/views/admin/billing/settings.blade.php',
        'resources/views/admin/billing/subscriptions/index.blade.php',
        'resources/views/admin/billing/subscriptions/show.blade.php',
        'resources/views/admin/billing/usage.blade.php',
        'resources/views/admin/channel-sessions/index.blade.php',
        'resources/views/admin/dashboard.blade.php',
        'resources/views/admin/guide/famille.blade.php',
        'resources/views/admin/guide/index.blade.php',
        'resources/views/admin/ledger-adjustments/create.blade.php',
        'resources/views/admin/ledger-adjustments/index.blade.php',
        'resources/views/admin/ledger-adjustments/show.blade.php',
        'resources/views/admin/money-exceptions/index.blade.php',
        'resources/views/admin/money-exceptions/show.blade.php',
        'resources/views/admin/notifications/index.blade.php',
        'resources/views/admin/notifications/show.blade.php',
        'resources/views/admin/organization-members/index.blade.php',
        'resources/views/admin/organization-members/invite.blade.php',
        'resources/views/admin/payments/show.blade.php',
        'resources/views/admin/portfolio/index.blade.php',
        'resources/views/admin/provider-applications/create.blade.php',
        'resources/views/admin/projects/create.blade.php',
        'resources/views/admin/projects/exports.blade.php',
        'resources/views/admin/reconciliation/new.blade.php',
        'resources/views/admin/wallets/index.blade.php',
        'resources/views/admin/wallets/ledger.blade.php',
        'resources/views/admin/webhook-deliveries/dead.blade.php',
        'resources/views/admin/webhook-deliveries/show.blade.php',
        'resources/views/admin/webhook-events/index.blade.php',
    ];

    /**
     * La ratchet : les écrans qui gardent encore des termes interdits.
     *
     * Chaque écran y est nommé avec les termes qu'il contient **encore** — le
     * test échoue si un terme annoncé a disparu (l'écran est fini : il faut le
     * remonter dans `REWRITTEN`) et si un terme nouveau s'y est glissé.
     *
     * Les trois derniers écrans appartiennent à un autre chantier : ils ne sont
     * pas réécrits ici, mais ils ne sont pas non plus laissés sans garde-fou.
     *
     * @var array<string, list<string>>
     */
    private const NOT_YET_REWRITTEN = [
        'resources/views/admin/webhook-endpoints/index.blade.php' => ['ADR', 'endpoint', 'quota', 'webhook'],
        'resources/views/admin/projects/show.blade.php' => ['endpoint', 'grand livre', 'provider', 'quota', 'wallet', 'webhook'],
        'resources/views/admin/billing/customers/show.blade.php' => [],
    ];

    /**
     * Les attributs dont la valeur est lue par un humain.
     *
     * @var list<string>
     */
    private const READABLE_ATTRIBUTES = [
        'title', 'label', 'hint', 'placeholder', 'aria-label', 'alt', 'value', 'content',
        'caption', 'empty', 'headers', 'message', 'confirm', 'text', 'note', 'summary',
        'description', 'legend', 'aria-valuetext',
    ];

    #[Test]
    public function les_ecrans_reecrits_ne_disent_plus_le_vocabulaire_du_code(): void
    {
        $offences = [];

        foreach (self::REWRITTEN as $screen) {
            $path = $this->pathOf($screen);
            self::assertFileExists($path, 'l\'écran '.$screen.' n\'existe plus : le garde-fou suit les écrans.');

            foreach ($this->offencesIn($screen) as $offence) {
                $offences[] = $offence;
            }
        }

        self::assertSame([], $offences, $this->explanation($offences));
    }

    #[Test]
    public function la_ratchet_est_exacte_elle_ne_peut_plus_grandir_ni_menager(): void
    {
        $problems = [];

        foreach (self::NOT_YET_REWRITTEN as $screen => $declared) {
            $path = $this->pathOf($screen);
            self::assertFileExists($path, 'l\'écran '.$screen.' n\'existe plus : le garde-fou suit les écrans.');

            $found = array_values(array_unique(array_column($this->offencesIn($screen), 0)));

            foreach ($declared as $term) {
                if (! in_array($term, $found, true)) {
                    $problems[] = $screen.' : le terme « '.$term.' » a disparu — l\'écran est réécrit, remontez-le dans REWRITTEN.';
                }
            }

            foreach ($found as $term) {
                if (! in_array($term, $declared, true)) {
                    $problems[] = $screen.' : le terme « '.$term.' » est apparu — réécrivez l\'écran, ou déclarez-le dans la ratchet.';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function aucun_ecran_de_la_console_n_echappe_au_garde_fou(): void
    {
        $unknown = [];

        foreach ($this->screens() as $screen) {
            if (in_array($screen, self::REWRITTEN, true)) {
                continue;
            }

            if (array_key_exists($screen, self::NOT_YET_REWRITTEN)) {
                continue;
            }

            $unknown[] = $screen;
        }

        self::assertSame([], $unknown, "Ces écrans de la console ne sont ni réécrits ni listés dans la ratchet :\n".implode("\n", $unknown));
    }

    /**
     * Les infractions d'un écran : terme interdit, ligne, chaîne fautive.
     *
     * @return list<array{0:string,1:int,2:string,3:string}>
     */
    private function offencesIn(string $screen): array
    {
        $path = $this->pathOf($screen);
        if (! is_file($path)) {
            return [];
        }

        $source = (string) file_get_contents($path);
        $allowed = self::ALLOWED[$screen] ?? [];
        $offences = [];

        foreach ($this->visibleStrings($source) as [$string, $line]) {
            foreach (self::FORBIDDEN as $term => $pattern) {
                if (preg_match($pattern, $string) !== 1) {
                    continue;
                }

                if (isset($allowed[$term])) {
                    continue;
                }

                $offences[] = [$term, $line, $string, $screen];
            }
        }

        return $offences;
    }

    /**
     * Le message d'échec : la chaîne, l'écran, la ligne, et ce qu'il faut faire.
     *
     * @param list<array{0:string,1:int,2:string,3:string}> $offences
     */
    private function explanation(array $offences): string
    {
        if ($offences === []) {
            return '';
        }

        $lines = ['La console doit parler à l\'opérateur, pas à l\'ingénieur :'];

        foreach ($offences as [$term, $line, $string, $screen]) {
            $lines[] = sprintf(
                '  · terme « %s » — %s, ligne %d : « %s »',
                $term,
                $screen,
                $line,
                $string,
            );
            $lines[] = '    → remplacez-le par le mot de l\'activité : un prestataire, un portefeuille, une offre, des droits accordés, la comptabilité du projet.';
        }

        return implode("\n", $lines);
    }

    /**
     * Les écrans Blade de la console, en chemin relatif à la racine.
     *
     * @return list<string>
     */
    private function screens(): array
    {
        $root = $this->repositoryRoot();
        $files = [];

        foreach (['resources/views/admin', 'resources/views/components/admin'] as $directory) {
            $path = $root.'/'.$directory;
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $directory.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
                }
            }
        }

        $files[] = 'resources/views/layouts/admin.blade.php';
        sort($files);

        return $files;
    }

    private function pathOf(string $screen): string
    {
        return $this->repositoryRoot().'/'.$screen;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Les chaînes visibles d'un gabarit Blade.
     *
     * On efface ce que l'opérateur ne lit pas : les commentaires Blade, les
     * blocs `@php`, les expressions `{{ … }}`, les scripts et les styles. Puis
     * on garde le texte entre les balises et les valeurs des attributs
     * lisibles. Les littéraux des expressions dynamiques (`:label="…"`) et des
     * blocs `@php` sont gardés eux aussi : ce sont des mots que l'opérateur lit.
     *
     * @return list<array{0:string,1:int}>
     */
    private function visibleStrings(string $source): array
    {
        $masked = $source;
        $hidden = [];

        foreach ([
            '/\{\{--.*?--\}\}/s',
            '/@php\b.*?@endphp/s',
            '/@php\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s',
            '/\{\{.*?\}\}/s',
            '/<\?php.*?\?>/s',
            '/<script\b.*?<\/script>/si',
            '/<style\b.*?<\/style>/si',
        ] as $pattern) {
            $hidden = array_merge($hidden, $this->regions($source, $pattern));
        }

        $masked = $this->blankOut($source, $hidden);

        // Les directives Blade (@if, @foreach, @section…) ne sont pas du texte.
        $masked = $this->blankOut($masked, $this->directiveRegions($masked));

        $found = [];

        // Le texte entre les balises.
        $withoutTags = $this->blankOut($masked, $this->tagRegions($masked));
        if (preg_match_all('/[^\n]+/', $withoutTags, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$chunk, $position]) {
                $chunk = trim((string) preg_replace('/\s+/u', ' ', $chunk));
                if ($chunk !== '') {
                    $found[] = [$chunk, $this->lineAt($source, (int) $position)];
                }
            }
        }

        // Les valeurs des attributs que l'on lit.
        if (preg_match_all('/([a-zA-Z_:@.\-\[\]]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $raw = (string) ($match[1][0] ?? '');

                // Un attribut dynamique (`:label="…"`) est une expression : sa
                // valeur est calculée, ses mots sont relus par l'extraction des
                // littéraux plus bas.
                if (str_starts_with($raw, ':') || str_starts_with($raw, 'v-bind:')) {
                    continue;
                }

                $name = strtolower($raw);
                if (! in_array($name, self::READABLE_ATTRIBUTES, true)) {
                    continue;
                }

                $value = trim((string) preg_replace('/\s+/u', ' ', (($match[2][0] ?? '') !== '') ? $match[2][0] : ($match[3][0] ?? '')));
                if ($value === '') {
                    continue;
                }

                $found[] = [$value, $this->lineAt($source, (int) $match[0][1])];
            }
        }

        // Les littéraux des expressions dynamiques, des blocs @php et des
        // directives @section / @yield : les mots que l'opérateur lit vraiment.
        foreach ($this->literals($source) as [$literal, $position]) {
            if ($this->looksLikeAKey($literal)) {
                continue;
            }

            $literal = trim((string) preg_replace('/\s+/u', ' ', $literal));
            if ($literal === '') {
                continue;
            }

            $found[] = [$literal, $this->lineAt($source, $position)];
        }

        return $found;
    }

    /**
     * Les littéraux de chaîne, avec leur position.
     *
     * @return list<array{0:string,1:int}>
     */
    private function literals(string $source): array
    {
        $blocks = [];

        if (preg_match_all('/(?::[a-zA-Z-]+|v-bind:[a-zA-Z-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $blocks[] = [(($match[2][0] ?? '') !== '') ? $match[2][0] : ($match[3][0] ?? ''), (int) $match[0][1]];
            }
        }

        if (preg_match_all('/@php\b(.*?)@endphp/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $blocks[] = [(string) $match[1][0], (int) $match[0][1]];
            }
        }

        if (preg_match_all('/@(?:section|yield)\s*\((.*?)\)/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $blocks[] = [(string) $match[1][0], (int) $match[0][1]];
            }
        }

        $literals = [];
        foreach ($blocks as [$expression, $position]) {
            foreach ($this->phpStrings($expression) as $literal) {
                $literals[] = [$literal, $position];
            }
        }

        return $literals;
    }

    /**
     * Les chaînes littérales d'un bout de PHP.
     *
     * Le lexique de PHP fait la différence entre une chaîne et un commentaire :
     * un `//` dans un commentaire n'est pas une chaîne, et une URL n'est pas
     * non plus un commentaire.
     *
     * @return list<string>
     */
    private function phpStrings(string $expression): array
    {
        $strings = [];
        $tokens = @token_get_all('<?php '.$expression);

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $raw = (string) $token[1];
            $inner = substr($raw, 1, -1);
            $strings[] = str_replace(['\\\\', "\\'", '\\"'], ['\\', "'", '"'], $inner);
        }

        return $strings;
    }

    /**
     * Une valeur qui ressemble à une route, un chemin ou une clé n'est pas une
     * phrase : on ne la traite pas comme un mot lu par l'opérateur.
     */
    private function looksLikeAKey(string $literal): bool
    {
        $trimmed = trim($literal);
        if ($trimmed === '') {
            return true;
        }

        if (preg_match('/\s/u', $trimmed) === 1) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9_]+$#', $trimmed) !== 1;
    }

    /**
     * @param list<array{0:int,1:int}> $ranges
     */
    private function blankOut(string $source, array $ranges): string
    {
        if ($ranges === []) {
            return $source;
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = '';
        $cursor = 0;
        foreach ($ranges as [$from, $to]) {
            if ($from > $cursor) {
                $out .= substr($source, $cursor, $from - $cursor);
                $cursor = $from;
            }

            if ($to > $cursor) {
                // On remplace par des espaces (les sauts de ligne sont gardés) :
                // la longueur du texte ne change pas, et les numéros de ligne
                // restent justes.
                $out .= (string) preg_replace('/[^\n]/', ' ', substr($source, $cursor, $to - $cursor));
                $cursor = $to;
            }
        }

        return $out.substr($source, $cursor);
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private function regions(string $source, string $pattern): array
    {
        $ranges = [];
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (! isset($match[0][1])) {
                    continue;
                }
                $ranges[] = [(int) $match[0][1], (int) $match[0][1] + strlen((string) $match[0][0])];
            }
        }

        return $ranges;
    }

    /**
     * Les balises HTML, respect des guillemets (un `>` peut être un `->`).
     *
     * @return list<array{0:int,1:int}>
     */
    private function tagRegions(string $source): array
    {
        $ranges = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            $position = strpos($source, '<', $offset);
            if ($position === false) {
                break;
            }

            $next = $source[$position + 1] ?? '';
            if ($next !== '' && ! preg_match('/[a-zA-Z\/!]/', $next)) {
                $offset = $position + 1;

                continue;
            }

            $quote = null;
            $end = $position;
            for ($i = $position + 1; $i < $length; $i++) {
                $char = $source[$i];
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;

                    continue;
                }
                if ($char === '>') {
                    $end = $i;
                    break;
                }
            }

            $ranges[] = [$position, $end + 1];
            $offset = $end + 1;
        }

        return $ranges;
    }

    /**
     * Les directives Blade (`@if (…)`, `@section(…)`, `@endif`…).
     *
     * @return list<array{0:int,1:int}>
     */
    private function directiveRegions(string $source): array
    {
        $ranges = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            $position = strpos($source, '@', $offset);
            if ($position === false) {
                break;
            }

            $offset = $position + 1;
            $previous = $position > 0 ? $source[$position - 1] : ' ';
            if (! preg_match('/[\s(\[{,]/', $previous)) {
                continue;
            }

            if (preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/', $source, $match, 0, $position) !== 1) {
                continue;
            }

            $end = $position + strlen((string) $match[0]);
            $cursor = $end;
            while ($cursor < $length && ($source[$cursor] === ' ' || $source[$cursor] === "\t")) {
                $cursor++;
            }

            if ($cursor < $length && $source[$cursor] === '(') {
                $depth = 0;
                $quote = null;
                for ($i = $cursor; $i < $length; $i++) {
                    $char = $source[$i];
                    if ($quote !== null) {
                        if ($char === $quote && ($i === 0 || $source[$i - 1] !== '\\')) {
                            $quote = null;
                        }

                        continue;
                    }
                    if ($char === '"' || $char === "'") {
                        $quote = $char;

                        continue;
                    }
                    if ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i + 1;
                            break;
                        }
                    }
                }
            }

            $ranges[] = [$position, $end];
            $offset = $end;
        }

        return $ranges;
    }

    private function lineAt(string $source, int $position): int
    {
        return substr_count(substr($source, 0, $position), "\n") + 1;
    }
}
