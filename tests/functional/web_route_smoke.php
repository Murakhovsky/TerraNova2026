<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$baseUrl = rtrim((string) ($argv[1] ?? getenv('COS_E2E_BASE_URL') ?: 'http://127.0.0.1:8081'), '/');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');

$blocks = [];
if (preg_match_all('/^([A-Za-z0-9_.-]+):\R((?:^[ \t].*\R?)*)/m', $routes, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $block = (string) $match[2];
        if (!preg_match('/^  path:\s*(.+)$/m', $block, $pathMatch)
            || !preg_match('/^  controller:\s*(.+)$/m', $block, $controllerMatch)) {
            continue;
        }
        preg_match('/^  methods:\s*\[(.*)]$/m', $block, $methodMatch);
        $methods = isset($methodMatch[1])
            ? array_values(array_filter(array_map(static fn(string $value): string => trim($value), explode(',', $methodMatch[1]))))
            : [];
        $blocks[] = [
            'name' => (string) $match[1],
            'path' => trim((string) $pathMatch[1], " \t\n\r\0\x0B'\""),
            'controller' => trim((string) $controllerMatch[1], " \t\n\r\0\x0B'\""),
            'methods' => $methods,
        ];
    }
}

$targets = array_values(array_filter($blocks, static function (array $route): bool {
    if (str_contains($route['path'], '{')) return false;
    if ($route['methods'] !== [] && !in_array('GET', $route['methods'], true) && !in_array('HEAD', $route['methods'], true)) return false;
    if (!str_starts_with($route['name'], 'cos_web_') && $route['name'] !== 'cos_symfony_home') return false;
    if (preg_match('/(?:api|health|sitemap|robots|telemetry|webhook|search|retry|publish|data_export|graph)/i', $route['name']) === 1) return false;
    return true;
}));

if (count($targets) < 25) {
    throw new RuntimeException('Static HTML route smoke discovered suspiciously few targets: ' . count($targets));
}

$accepted = [200, 301, 302, 303, 307, 308, 401, 403, 410];
$failures = [];
$results = [];

foreach ($targets as $route) {
    $url = $baseUrl . ($route['path'] === '/' ? '/' : '/' . ltrim($route['path'], '/'));
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 12,
            'header' => "User-Agent: COS-Final-Route-Audit/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    if (isset($headers[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headers[0], $statusMatch)) {
        $status = (int) $statusMatch[1];
    }

    $results[] = ['name' => $route['name'], 'path' => $route['path'], 'status' => $status];
    if (!in_array($status, $accepted, true)) {
        $failures[] = $route['name'] . ' ' . $route['path'] . ' -> HTTP ' . ($status ?: 'no response');
        continue;
    }

    if ($status === 200 && $body !== false) {
        $contentType = '';
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Content-Type:') === 0) {
                $contentType = strtolower((string) $header);
                break;
            }
        }
        if (str_contains($contentType, 'text/html') && stripos((string) $body, '<html') === false) {
            $failures[] = $route['name'] . ' ' . $route['path'] . ' returned HTTP 200 text/html without an HTML document.';
        }
    }
}

if ($failures !== []) {
    throw new RuntimeException("Static routed-page smoke failed:\n- " . implode("\n- ", $failures));
}

echo json_encode([
    'ok' => true,
    'suite' => 'Final UI static routed-page smoke',
    'base_url' => $baseUrl,
    'routes_checked' => count($results),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
