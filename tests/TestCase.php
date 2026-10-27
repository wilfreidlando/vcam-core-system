<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ADR-0083 §2 — the personal-data keys come from the deployment
        // environment, **never** from the repository (a key in a file is
        // a key in every backup of it). The test environment therefore
        // mints a throwaway keyring of its own: it encrypts and decrypts
        // within the test, and it protects nothing.
        //
        // Without it, every test that touches an encrypted column fails
        // with `MissingPersonalDataKey` — the loud failure the service is
        // designed to raise, but a failure that says nothing about the
        // code under test.
        config([
            'privacy.encryption_keys' => 'v1:base64:'.base64_encode(random_bytes(32)),
            'privacy.blind_index_key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
    }
}
