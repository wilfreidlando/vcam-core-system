<?php

declare(strict_types=1);

/**
 * ADR-0083 §2 — personal data is encrypted with a key of its own, never
 * with `APP_KEY`, and looked up through blind indexes computed with a
 * second key. Both keys come from the deployment environment
 * (ADR-0064), never from the repository nor from the database.
 *
 * `PII_ENCRYPTION_KEYS` is a versioned keyring: `v2:base64:<key>` encrypts,
 * every listed version decrypts. The first entry is the current one; a
 * rotation adds a new first entry and keeps the old one until the rows
 * have been re-encrypted.
 */
return [
    /**
     * Versioned keyring, comma-separated: `v2:base64:<key>,v1:base64:<key>`.
     */
    'encryption_keys' => (string) env('PII_ENCRYPTION_KEYS', ''),

    /**
     * Key of the blind indexes (HMAC-SHA256). A distinct key: losing it
     * makes every index useless, leaking it makes them reversible.
     */
    'blind_index_key' => (string) env('PII_BLIND_INDEX_KEY', ''),
];
