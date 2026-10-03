<?php

declare(strict_types=1);

// Vérifie la signature d'un webhook Core (PHP ≥ 8.0, aucune dépendance).
// Utilisation dans votre contrôleur : verifyCoreSignature($secret, $rawBody,
// $_SERVER['HTTP_X_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_SIGNATURE'] ?? '').

function verifyCoreSignature(string $secret, string $rawBody, string $timestamp, string $signature, int $toleranceSeconds = 300): bool
{
    // 1. Rejeter un message trop ancien (anti-rejeu).
    if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $toleranceSeconds) {
        return false;
    }

    // 2. Recalculer la signature sur "<timestamp>.<corps brut>" — le corps
    //    tel que reçu, AVANT tout json_decode.
    $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    // 3. Comparer en temps constant.
    return hash_equals($expected, $signature);
}

// Exécutable en ligne de commande (utilisé par les tests de cette doc) :
//   WEBHOOK_SECRET=… X_TIMESTAMP=… X_SIGNATURE=… php verify-signature.php < body.json
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $ok = verifyCoreSignature(
        (string) getenv('WEBHOOK_SECRET'),
        (string) stream_get_contents(STDIN),
        (string) getenv('X_TIMESTAMP'),
        (string) getenv('X_SIGNATURE'),
    );
    echo $ok ? "valid\n" : "invalid\n";
    exit($ok ? 0 : 1);
}
