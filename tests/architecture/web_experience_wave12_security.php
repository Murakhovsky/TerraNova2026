<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Security/SessionCsrfValidator.php',
    'symfony/src/Security/TenantPermissionVoter.php',
    'symfony/src/Security/SecurityHeadersSubscriber.php',
    'symfony/src/Security/LoginRateLimiter.php',
    'symfony/src/Security/PrivateDownloadResponseFactory.php',
    'symfony/src/Application/Security/Contract/MutationLockManagerInterface.php',
    'symfony/src/Infrastructure/Security/MySqlMutationLockManager.php',
    'symfony/src/Command/SecurityPlatformSmokeCommand.php',
    'app/migrations/20260921_000063_web_security_rate_limits.sql',
    'docs/03-architecture/web-security-platform.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.19 Security artifact is missing: ' . $relative);
    }
}

$csrf = (string) file_get_contents($root . '/symfony/src/Security/SessionCsrfValidator.php');
foreach (['hash_equals', 'X-CSRF-Token', "cos_csrf_token"] as $marker) {
    if (!str_contains($csrf, $marker)) {
        throw new RuntimeException('CSRF security contract is missing: ' . $marker);
    }
}

foreach ([
    'symfony/src/Http/Api/V1/Controller/SalesWriteController.php',
    'symfony/src/Http/Api/V1/Controller/PropertyController.php',
    'symfony/src/Http/Api/V1/Controller/DocumentsController.php',
    'symfony/src/Http/Api/V1/Controller/DiagnosticController.php',
    'symfony/src/Http/Api/V1/Controller/OperationsWriteController.php',
] as $relative) {
    $contents = (string) file_get_contents($root . '/' . $relative);
    if (!str_contains($contents, 'SessionCsrfValidator')) {
        throw new RuntimeException('Authenticated mutation controller is missing CSRF validation: ' . $relative);
    }
}

$voter = (string) file_get_contents($root . '/symfony/src/Security/TenantPermissionVoter.php');
foreach (['TenantContextProviderInterface', 'belongsTo(', 'allows('] as $marker) {
    if (!str_contains($voter, $marker)) {
        throw new RuntimeException('Tenant authorization contract is missing: ' . $marker);
    }
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
foreach (['IS_AUTHENTICATED_FULLY', 'ROLE_MANAGER', 'SessionAuthenticator', 'SpatialBearerAuthenticator'] as $marker) {
    if (!str_contains($security, $marker)) {
        throw new RuntimeException('Authorization boundary is missing: ' . $marker);
    }
}

$framework = (string) file_get_contents($root . '/symfony/config/packages/framework.yaml');
foreach (['name: COSSESSID', 'cookie_secure: auto', 'cookie_httponly: true', 'cookie_samesite: lax'] as $marker) {
    if (!str_contains($framework, $marker)) {
        throw new RuntimeException('Session cookie policy is missing: ' . $marker);
    }
}

$auth = (string) file_get_contents($root . '/symfony/src/Web/Auth/AuthPageController.php');
foreach (['session->migrate(true)', "cos_csrf_token", 'LoginRateLimiter', 'HTTP_TOO_MANY_REQUESTS', 'Retry-After'] as $marker) {
    if (!str_contains($auth, $marker)) {
        throw new RuntimeException('Authentication/session security contract is missing: ' . $marker);
    }
}

$headers = (string) file_get_contents($root . '/symfony/src/Security/SecurityHeadersSubscriber.php');
foreach ([
    'Content-Security-Policy',
    "object-src 'none'",
    "frame-ancestors 'none'",
    'X-Content-Type-Options',
    'Referrer-Policy',
    'Permissions-Policy',
    'X-Frame-Options',
] as $marker) {
    if (!str_contains($headers, $marker)) {
        throw new RuntimeException('Security response header is missing: ' . $marker);
    }
}

$media = (string) file_get_contents($root . '/app/Infrastructure/Media/MediaStorageService.php');
foreach (['MAX_IMAGE_BYTES', 'detectMime(', "'.upload'", 'hash_file'] as $marker) {
    if (!str_contains($media, $marker)) {
        throw new RuntimeException('Media upload hardening is missing: ' . $marker);
    }
}

$spatial = (string) file_get_contents($root . '/app/Infrastructure/Media/SpatialAssetService.php');
foreach (['maxUploadBytes', 'verifySignature(', 'detectMime(', 'FORMATS'] as $marker) {
    if (!str_contains($spatial, $marker)) {
        throw new RuntimeException('Spatial upload hardening is missing: ' . $marker);
    }
}

$storage = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    "%kernel.project_dir%/var/storage",
    'MutationLockManagerInterface:',
    'MySqlMutationLockManager',
] as $marker) {
    if (!str_contains($storage, $marker)) {
        throw new RuntimeException('Private storage/locking DI contract is missing: ' . $marker);
    }
}

$download = (string) file_get_contents($root . '/symfony/src/Security/PrivateDownloadResponseFactory.php');
foreach (['DISPOSITION_ATTACHMENT', 'private, no-store', 'nosniff', 'basename('] as $marker) {
    if (!str_contains($download, $marker)) {
        throw new RuntimeException('Private download hardening is missing: ' . $marker);
    }
}

$rate = (string) file_get_contents($root . '/symfony/src/Security/LoginRateLimiter.php');
foreach (['FOR UPDATE', 'blocked_until', 'cos_security_rate_limits', 'beginTransaction'] as $marker) {
    if (!str_contains($rate, $marker)) {
        throw new RuntimeException('Rate-limit concurrency contract is missing: ' . $marker);
    }
}

$lock = (string) file_get_contents($root . '/symfony/src/Infrastructure/Security/MySqlMutationLockManager.php');
foreach (['GET_LOCK', 'RELEASE_LOCK', 'finally'] as $marker) {
    if (!str_contains($lock, $marker)) {
        throw new RuntimeException('Mutation locking contract is missing: ' . $marker);
    }
}

$idempotencyEvidence = [
    'app/Domains/Property/Application/Contract/PropertyMutationReceiptInterface.php',
    'app/Platform/Documents/Contract/DocumentMutationReceiptInterface.php',
    'app/Domains/Service/Application/Contract/ServiceMutationReceiptInterface.php',
];
foreach ($idempotencyEvidence as $relative) {
    $contents = (string) file_get_contents($root . '/' . $relative);
    if (!str_contains($contents, 'claim(')) {
        throw new RuntimeException('Atomic idempotency receipt is missing: ' . $relative);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/SecurityPlatformSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:security:smoke'")) {
    throw new RuntimeException('Web Security runtime smoke is missing.');
}

echo "Wave 12.19 Web Security passed.\n";
