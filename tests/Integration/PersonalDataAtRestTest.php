<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Support\Privacy\BlindIndex;
use Core\Support\Privacy\MissingPersonalDataKey;
use Core\Support\Privacy\PersonalDataCipher;
use Core\Support\Privacy\PersonalDataKind;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0083 §2 — what the platform promises about personal data at rest:
 * the column holds ciphertext, never a readable value; the exact-match
 * lookup goes through a blind index; both keys come from the environment.
 *
 * This test lives in the application shell, not in `packages/support`:
 * the shared kernel holds no persistence (G3d), so it may not touch the
 * database even from its own tests.
 */
final class PersonalDataAtRestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_personal_column_holds_ciphertext_and_never_a_readable_value(): void
    {
        // The bindings read the configuration when they are resolved, so
        // setting it here is enough (no application reload).
        config(['privacy.encryption_keys' => 'v1:base64:'.base64_encode(str_repeat('p', 32))]);
        config(['privacy.blind_index_key' => 'base64:'.base64_encode(str_repeat('i', 32))]);

        Schema::create('people_probe', function (Blueprint $table): void {
            $table->id();
            $table->text('email_ciphertext');
            $table->char('email_bidx', 64);
        });

        $cipher = app(PersonalDataCipher::class);
        $index = app(BlindIndex::class);

        DB::table('people_probe')->insert([
            'email_ciphertext' => $cipher->encrypt('alice@domaine.cm'),
            'email_bidx' => $index->of('alice@domaine.cm', PersonalDataKind::Email),
        ]);

        $stored = DB::table('people_probe')->value('email_ciphertext');
        self::assertIsString($stored);

        self::assertStringNotContainsString('alice@domaine.cm', $stored);
        self::assertStringStartsWith('pii:v1:', $stored);
        self::assertSame('alice@domaine.cm', $cipher->decrypt($stored));

        // The blind index finds the row without the database ever holding
        // the value itself.
        $found = DB::table('people_probe')
            ->where('email_bidx', $index->of('ALICE@domaine.cm', PersonalDataKind::Email))
            ->first();
        self::assertNotNull($found);
    }

    #[Test]
    public function a_deployment_without_keys_fails_loudly_instead_of_storing_anything(): void
    {
        config(['privacy.encryption_keys' => '']);
        config(['privacy.blind_index_key' => '']);

        $this->expectException(MissingPersonalDataKey::class);

        app(PersonalDataCipher::class)->encrypt('alice@domaine.cm');
    }
}
