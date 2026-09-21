<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Security/AuthenticatedSessionCsrfSubscriber.php',
    'symfony/src/Security/RequestRateLimitSubscriber.php',
    'symfony/src/Security/SecurityRateLimiter.php',
    'symfony/src/Security/RateLimitDecision.php',
    'symfony/src/Security/SecurityHeadersSubscriber.php',
    'symfony/src/Security/SecureDownloadResponseFactory.php',
    'symfony/src/Infrastructure/Concurrency/MySqlAdvisoryLock.php',
    'app/Infrastructure/Media/UploadQuarantineService.php',
    'app/Infrastructure/Media/QuarantinedUpload.php',
    'symfony/src/Command/WebSecuritySmokeCommand.php',
    'docs/03-architecture/web-security-closure.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.19 security artifact is missing: ' . $relative);
    }
}

$csrf = (string) file_get_contents($root . '/symfony/src/Security/AuthenticatedSessionCsrfSubscriber.php');
foreach ([
    "['POST', 'PUT', 'PATCH', 'DELETE']",
    "tn_auth_user_id",
    "invalid_csrf_token",
    "Bearer",
    "protectedSessionPath",
] as $marker) {
    if (!str_contains($csrf, $marker)) {
        throw new RuntimeException('Authenticated session CSRF contract is incomplete: ' . $marker);
    }
}

$sessionCsrf = (string) file_get_contents($root . '/symfony/src/Security/SessionCsrfValidator.php');
foreach (['hash_equals', 'X-CSRF-Token', 'csrf_token', 'cos_csrf_token'] as $marker) {
    if (!str_contains($sessionCsrf, $marker)) {
        throw new RuntimeException('Session CSRF validator contract is incomplete: ' . $marker);
    }
}

$auth = (string) file_get_contents($root . '/symfony/src/Web/Auth/AuthPageController.php');
foreach (['migrate(true)', "cos_csrf_token", 'random_bytes(32)', 'invalidate()'] as $marker) {
    if (!str_contains($auth, $marker)) {
        throw new RuntimeException('Session lifecycle hardening is missing: ' . $marker);
    }
}

$framework = (string) file_get_contents($root . '/symfony/config/packages/framework.yaml');
foreach ([
    'name: COSSESSID',
    'cookie_secure: auto',
    'cookie_httponly: true',
    'cookie_samesite: lax',
    'cookie_lifetime: 0',
    'gc_maxlifetime: 28800',
] as $marker) {
    if (!str_contains($framework, $marker)) {
        throw new RuntimeException('Session configuration is incomplete: ' . $marker);
    }
}

$voter = (string) file_get_contents($root . '/symfony/src/Security/TenantPermissionVoter.php');
foreach (['belongsTo(', 'allows(', 'TenantScopedInterface', 'OrganizationId'] as $marker) {
    if (!str_contains($voter, $marker)) {
        throw new RuntimeException('Tenant authorization contract is incomplete: ' . $marker);
    }
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
foreach ([
    'IS_AUTHENTICATED_FULLY',
    'ROLE_MANAGER',
    "path: '^/api/v1(?:/|$)'",
    "path: '^/admin(?:/|$)'",
    "path: '^/sales(?:/|$)'",
] as $marker) {
    if (!str_contains($security, $marker)) {
        throw new RuntimeException('Authorization boundary is incomplete: ' . $marker);
    }
}

$headers = (string) file_get_contents($root . '/symfony/src/Security/SecurityHeadersSubscriber.php');
foreach ([
    'Content-Security-Policy',
    "default-src 'self'",
    "object-src 'none'",
    "frame-ancestors 'none'",
    'X-Content-Type-Options',
    'Referrer-Policy',
    'Permissions-Policy',
    'Strict-Transport-Security',
] as $marker) {
    if (!str_contains($headers, $marker)) {
        throw new RuntimeException('Security header contract is incomplete: ' . $marker);
    }
}

$rate = (string) file_get_contents($root . '/symfony/src/Security/RequestRateLimitSubscriber.php');
foreach ([
    "'auth.login', 10, 300",
    "'auth.register', 5, 900",
    "'public.analytics', 120, 60",
    "'authenticated.write', 240, 60",
    'HTTP_TOO_MANY_REQUESTS',
    'Retry-After',
] as $marker) {
    if (!str_contains($rate, $marker)) {
        throw new RuntimeException('Rate limit policy is incomplete: ' . $marker);
    }
}

$limiter = (string) file_get_contents($root . '/symfony/src/Security/SecurityRateLimiter.php');
foreach (['CacheItemPoolInterface', 'synchronized(', 'expiresAt(', 'RateLimitDecision'] as $marker) {
    if (!str_contains($limiter, $marker)) {
        throw new RuntimeException('Rate limiter implementation is incomplete: ' . $marker);
    }
}

$lock = (string) file_get_contents($root . '/symfony/src/Infrastructure/Concurrency/MySqlAdvisoryLock.php');
foreach (['GET_LOCK', 'RELEASE_LOCK', 'finally', 'synchronized'] as $marker) {
    if (!str_contains($lock, $marker)) {
        throw new RuntimeException('Locking contract is incomplete: ' . $marker);
    }
}

$quarantine = (string) file_get_contents($root . '/app/Infrastructure/Media/UploadQuarantineService.php');
foreach (['rootDirectory', '0700', '0600', 'finfo_file', "hash_file('sha256'"] as $marker) {
    if (!str_contains($quarantine, $marker)) {
        throw new RuntimeException('Upload quarantine contract is incomplete: ' . $marker);
    }
}

$spatial = (string) file_get_contents($root . '/app/Infrastructure/Media/SpatialAssetService.php');
foreach ([
    '$this->quarantine->quarantine',
    '$quarantined->releaseTo',
    'verifiedMime(',
    'verifySignature(',
] as $marker) {
    if (!str_contains($spatial, $marker)) {
        throw new RuntimeException('Spatial upload security is incomplete: ' . $marker);
    }
}

if (str_contains($spatial, 'move_uploaded_file($tmp, $absolutePath)')) {
    throw new RuntimeException('Spatial uploads must not move directly from PHP temp storage into public storage.');
}

$downloads = (string) file_get_contents($root . '/symfony/src/Security/SecureDownloadResponseFactory.php');
foreach (['BinaryFileResponse', 'DISPOSITION_ATTACHMENT', 'no-store, private', 'nosniff', 'realpath'] as $marker) {
    if (!str_contains($downloads, $marker)) {
        throw new RuntimeException('Secure download contract is incomplete: ' . $marker);
    }
}

$idempotencyFiles = [
    'symfony/src/Application/Property/Service/PropertyWriteService.php',
    'app/Platform/Documents/Service/DocumentsRuntimeService.php',
    'symfony/src/Http/Api/V1/Controller/SalesCommunicationController.php',
];

foreach ($idempotencyFiles as $relative) {
    $contents = (string) file_get_contents($root . '/' . $relative);
    if (!str_contains($contents, 'idempotency') && !str_contains($contents, 'Idempotency')) {
        throw new RuntimeException('Idempotency contract is missing from write path: ' . $relative);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'App\\Security\\SecurityRateLimiter:',
    "\$cache: '@cache.app'",
    'Infrastructure\\Media\\UploadQuarantineService:',
    "\$quarantine: '@Infrastructure\\Media\\UploadQuarantineService'",
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('Security service wiring is incomplete: ' . $marker);
    }
}

echo "Wave 12.19 Web Security passed.\n";
