<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/app/config/trusted_proxy.php';

$https = [
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTPS' => 'off',
    'REQUEST_SCHEME' => 'http',
    'SERVER_PORT' => '80',
];
normalizeTrustedProxyRequest($https);
if (($https['HTTPS'] ?? null) !== 'on') {
    throw new RuntimeException('Trusted HTTPS proxy must normalize HTTPS=on.');
}
if (($https['REQUEST_SCHEME'] ?? null) !== 'https' || ($https['SERVER_PORT'] ?? null) !== '443') {
    throw new RuntimeException('Trusted HTTPS proxy must normalize scheme and port.');
}

$chain = [
    'HTTP_X_FORWARDED_PROTO' => ' https, http ',
];
normalizeTrustedProxyRequest($chain);
if (($chain['HTTPS'] ?? null) !== 'on' || ($chain['REQUEST_SCHEME'] ?? null) !== 'https') {
    throw new RuntimeException('Forwarded scheme chains must use the first edge value.');
}

$http = [
    'HTTP_X_FORWARDED_PROTO' => 'http',
    'HTTPS' => 'on',
    'REQUEST_SCHEME' => 'https',
    'SERVER_PORT' => '443',
];
normalizeTrustedProxyRequest($http);
if (($http['HTTPS'] ?? null) !== 'off' || ($http['REQUEST_SCHEME'] ?? null) !== 'http' || ($http['SERVER_PORT'] ?? null) !== '80') {
    throw new RuntimeException('Trusted HTTP proxy must normalize the request back to HTTP.');
}

$invalid = [
    'HTTP_X_FORWARDED_PROTO' => 'javascript',
    'HTTPS' => 'off',
    'REQUEST_SCHEME' => 'http',
];
normalizeTrustedProxyRequest($invalid);
if (($invalid['REQUEST_SCHEME'] ?? null) !== 'http') {
    throw new RuntimeException('Invalid forwarded schemes must be ignored.');
}

$direct = [
    'HTTPS' => 'on',
    'REQUEST_SCHEME' => 'https',
    'SERVER_PORT' => '443',
];
normalizeTrustedProxyRequest($direct);
if (($direct['HTTPS'] ?? null) !== 'on' || ($direct['REQUEST_SCHEME'] ?? null) !== 'https') {
    throw new RuntimeException('Direct requests without forwarding metadata must remain unchanged.');
}

echo "Trusted proxy request normalization passed.\n";
