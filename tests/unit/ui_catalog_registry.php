<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Dev/UiCatalogEntry.php';
require dirname(__DIR__, 2) . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php';

use App\Web\Experience\Dev\UiCatalogRegistry;

$registry = new UiCatalogRegistry();
$entries = $registry->entries();
$names = array_map(static fn ($entry): string => $entry->name, $entries);

assert(count($entries) >= 59);
assert(count($names) === count(array_unique($names)));
assert(in_array('CosButton', $names, true));
assert(in_array('CosDataGrid', $names, true));
assert(in_array('CosWorkspace', $names, true));
assert(in_array('CosAgentRun', $names, true));

$categories = $registry->categories();
foreach (['Foundation', 'Actions', 'Feedback', 'Forms', 'Interaction', 'Data', 'Business', 'Workspace', 'Realtime', 'AI'] as $category) {
    assert(in_array($category, $categories, true));
}

$stats = $registry->stats();
assert($stats['components'] === count($entries));
assert($stats['categories'] === count($categories));

echo "UI Catalog registry OK\n";
