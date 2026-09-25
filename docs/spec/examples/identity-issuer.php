<?php

declare(strict_types=1);

/**
 * Host-backend reference function, NOT an HTTP endpoint or an authentication implementation.
 * Dependency: a verified compatible firebase/php-jwt package (see REFERENCES.md R14).
 * The caller must obtain $authenticatedSubject from its SERVER-VERIFIED member session.
 * Never pass user_id/email directly from a request into this signer.
 * Never expose $base64Secret to JavaScript, URLs, logs, or error messages.
 */
use Firebase\JWT\JWT;

function issueYacsIdentity(
    string $authenticatedSubject,
    string $issuer,
    string $workspaceId,
    string $brandId,
    string $inboxKey,
    string $keyId,
    string $base64Secret,
): string {
    foreach ([$authenticatedSubject, $issuer, $workspaceId, $brandId, $inboxKey, $keyId] as $value) {
        if ($value === '') {
            throw new InvalidArgumentException('Missing trusted identity configuration.');
        }
    }

    $secret = base64_decode($base64Secret, true);
    if ($secret === false || strlen($secret) < 32) {
        throw new InvalidArgumentException('Identity signing key must contain at least 256 random bits.');
    }
    if (!class_exists(JWT::class)) {
        throw new RuntimeException('Install and autoload the verified JWT dependency first.');
    }

    $now = time();
    return JWT::encode([
        'iss' => $issuer,
        'aud' => 'yacs:visitor',
        'sub' => $authenticatedSubject,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'inbox_key' => $inboxKey,
        'iat' => $now,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(16)),
    ], $secret, 'HS256', $keyId);
}
