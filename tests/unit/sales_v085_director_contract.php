<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = $read('app/Domains/Sales/Application/Service/SalesDirectorCockpitService.php');
$api = $read('app/Interfaces/Api/Controller/SalesDirectorController.php');
$routes = $read('app/Interfaces/Web/Routing/SalesDirectorRoutes.php');
$web = $read('app/Interfaces/Web/Controller/SalesController.php');
$view = $read('app/Interfaces/Web/View/sales/director.phtml');
$bootstrap = $read('app/Bootstrap/SalesHistoricalIntelligenceServices.php');
$module = require $root . '/app/Domains/Sales/module.php';

$assert(($module['version'] ?? null) === '0.8.5', 'Sales module must be V0.8.5.');
$assert(($module['schema_version'] ?? null) === '0.8.3', 'V0.8.5 must not invent a schema migration.');
$assert(str_contains($service, "sales.director.cockpit.v1"), 'Director contract must be explicitly versioned.');
$assert(str_contains($service, "Money is always returned by currency"), 'Director contract must preserve currency boundaries.');
$assert(str_contains($routes, '/api/sales/director/overview'), 'Director overview API route is required.');
$assert(str_contains($api, "new DateTimeImmutable()"), 'Director API must use a current snapshot timestamp.');
$assert(!str_contains($api, "getQuery('as_of'"), 'Director API must not pretend current forecast can be reconstructed historically.');
$assert(str_contains($web, "getShared('salesDirectorCockpit')"), 'Web Director must consume the Director cockpit contract.');
$assert(str_contains($bootstrap, "'salesDirectorCockpit'"), 'Director cockpit service must be wired in DI.');
$assert(str_contains($view, 'Attributed facts only'), 'Cockpit must disclose owner-at-time attribution semantics.');
$assert(str_contains($view, 'Currencies are never mixed'), 'Cockpit must disclose currency-safe monetary semantics.');
$assert(!str_contains($web, 'directorAnalytics($org'), 'Director page must not use the legacy directorAnalytics payload.');

fwrite(STDOUT, "Sales V0.8.5 director contract: OK\n");
