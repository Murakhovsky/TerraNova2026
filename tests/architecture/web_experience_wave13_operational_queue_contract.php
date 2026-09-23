<?php

declare(strict_types=1);

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PageArchetypeRegistry;

$root = dirname(__DIR__, 2);
require $root . '/symfony/vendor/autoload.php';

$definition = (new PageArchetypeRegistry())->get(PageArchetype::OperationalQueue);

foreach (['PageHeader', 'EntityList'] as $required) {
    if (!in_array($required, $definition->requiredPatterns, true)) {
        throw new RuntimeException('Operational Queue lost required pattern: ' . $required);
    }
}

foreach (['KpiStrip', 'FilterBar', 'ActionBar', 'EmptyState', 'ErrorState'] as $optional) {
    if (!in_array($optional, $definition->optionalPatterns, true)) {
        throw new RuntimeException('Operational Queue optional pattern contract is incomplete: ' . $optional);
    }
}

echo "Wave 13 Operational Queue pattern contract passed.\n";
