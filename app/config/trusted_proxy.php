<?php
declare(strict_types=1);

/**
 * Normalizes the original public request scheme before any web service reads
 * $_SERVER. The application container is reachable only through the host
 * reverse proxy (127.0.0.1:8080 on the host), which overwrites
 * X-Forwarded-Proto/Port for public traffic.
 */
if (!function_exists('normalizeTrustedProxyRequest')) {
    function normalizeTrustedProxyRequest(array &$server): void
    {
        $forwardedProto = (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwardedProto === '') {
            return;
        }

        // RFC-style forwarding chains are comma-separated. The edge proxy
        // overwrites this header, so the first value is the public scheme.
        $scheme = strtolower(trim(explode(',', $forwardedProto, 2)[0]));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $server['REQUEST_SCHEME'] = $scheme;

        if ($scheme === 'https') {
            $server['HTTPS'] = 'on';
            $server['SERVER_PORT'] = '443';
            return;
        }

        $server['HTTPS'] = 'off';
        $server['SERVER_PORT'] = '80';
    }
}

normalizeTrustedProxyRequest($_SERVER);
