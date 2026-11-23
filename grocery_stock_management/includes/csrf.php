<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE && !session_start([
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
])) {
    http_response_code(500);
    exit('Security token service is unavailable. Please try again later.');
}

function getCsrfToken(): string
{
    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || !preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isCsrfTokenValid($candidate): bool
{
    return is_string($candidate)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $candidate);
}
