<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\TableOwnership;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$expected = ['Content', 'Identity', 'Property', 'Sales', 'Spatial'];
$actual = array_map(
    static fn (string $path): string => basename($path),
    glob($root . '/app/Domains/*', GLOB_ONLYDIR) ?: [],
);
sort($expected);
sort($actual);
if ($actual !== $expected) {
    throw new RuntimeException(sprintf(
        'Domain catalog changed without an architecture decision. Expected %s, got %s.',
        implode(', ', $expected),
        implode(', ', $actual),
    ));
}

foreach ($expected as $domain) {
    $application = $root . '/app/Domains/' . $domain . '/Application';
    if (!is_dir($application) || (glob($application . '/**/*.php') ?: []) === []) {
        throw new RuntimeException($domain . ' has no application boundary.');
    }
}

foreach (['Analytics', 'Notification', 'Media', 'Telegram'] as $technicalPseudoDomain) {
    if (is_dir($root . '/app/Domains/' . $technicalPseudoDomain)) {
        throw new RuntimeException($technicalPseudoDomain . ' is a technical capability, not an approved Domain.');
    }
}

if (!is_file($root . '/app/Infrastructure/Platform/Analytics/MysqlPropertyFunnelAnalytics.php')) {
    throw new RuntimeException('Platform analytics adapter is missing.');
}
if (TableOwnership::ownerOf('tn_analytics_events') !== 'Platform') {
    throw new RuntimeException('Technical analytics storage must be owned by Platform.');
}

echo "Domain boundaries passed: only approved business contexts remain under Domains.\n";

