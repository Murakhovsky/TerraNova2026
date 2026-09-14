<?php
declare(strict_types=1);

use Infrastructure\Platform\Persistence\TableOwnership;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$domains = array_map(
    static fn (string $path): string => basename($path),
    glob($root . '/app/Domains/*', GLOB_ONLYDIR) ?: [],
);
sort($domains);

if ($domains === []) {
    throw new RuntimeException('No business Domains were discovered.');
}

foreach ($domains as $domain) {
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

echo sprintf(
    "Domain boundaries passed: %d business contexts discovered dynamically.\n",
    count($domains),
);
