<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('WEB V0.16 artifact is missing: ' . $path);
    }
    return (string) file_get_contents($full);
};

$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ': ' . $needle);
    }
};

$components = [
    'PageHeader' => 'app/Interfaces/Web/View/components/ui/page_header.phtml',
    'EntityHeader' => 'app/Interfaces/Web/View/components/ui/entity_header.phtml',
    'Panel' => 'app/Interfaces/Web/View/components/ui/panel.phtml',
    'DataTable' => 'app/Interfaces/Web/View/components/ui/data_table.phtml',
    'FilterBar' => 'app/Interfaces/Web/View/components/ui/filter_bar.phtml',
    'ActionBar' => 'app/Interfaces/Web/View/components/ui/action_bar.phtml',
    'Drawer' => 'app/Interfaces/Web/View/components/ui/drawer.phtml',
    'Stage' => 'app/Interfaces/Web/View/components/ui/stage.phtml',
    'Status' => 'app/Interfaces/Web/View/components/ui/status_badge.phtml',
    'Breadcrumbs' => 'app/Interfaces/Web/View/components/ui/breadcrumbs.phtml',
];
foreach ($components as $name => $path) {
    $read($path);
}

$designSystem = $read('frontend/styles/design-system.css');
$contains($designSystem, "@import './canonical-components.css';", 'Canonical component stylesheet must be part of the design system');

$css = $read('frontend/styles/canonical-components.css');
foreach ([
    '.tn-ui-entity-head',
    '.tn-ui-panel--flush',
    '.tn-ui-stage',
    '.tn-ui-drawer',
    '.tn-ui-action-bar',
    '.tn-ui-filter-bar__group',
    '.tn-ui-data-table',
    '.tn-ui-breadcrumbs',
    'data-responsive="cards"',
    '@media (max-width: 760px)',
    '.tn-ui-drawer { width: 100vw',
] as $needle) {
    $contains($css, $needle, 'Canonical component CSS contract is incomplete');
}

$breadcrumbs = $read($components['Breadcrumbs']);
foreach (['<nav class="tn-ui-breadcrumbs"', '<ol>', 'aria-current="page"', "'items'"] as $needle) {
    $contains($breadcrumbs, $needle, 'Breadcrumbs semantic navigation contract is incomplete');
}

$pageHeader = $read($components['PageHeader']);
$contains($pageHeader, "components/ui/action_bar", 'PageHeader actions must use canonical ActionBar');

$entityHeader = $read($components['EntityHeader']);
foreach (["components/ui/status_badge", "components/ui/action_bar", 'tn-ui-entity-head__meta'] as $needle) {
    $contains($entityHeader, $needle, 'EntityHeader composition contract is incomplete');
}

$dataTable = $read($components['DataTable']);
foreach (["components/ui/status_badge", "components/ui/stage", 'data-label=', "'_href'"] as $needle) {
    $contains($dataTable, $needle, 'DataTable server-rendered contract is incomplete');
}

$status = $read($components['Status']);
$contains($status, "['neutral', 'positive', 'warning', 'danger', 'info']", 'Status tones must remain semantic and closed');

$stage = $read($components['Stage']);
$contains($stage, "['neutral', 'accent', 'positive', 'warning', 'danger']", 'Stage tones must expose workflow accent without changing Status semantics');

$drawer = $read($components['Drawer']);
foreach (['<dialog', 'data-ui-drawer', 'data-ui-drawer-close'] as $needle) {
    $contains($drawer, $needle, 'Drawer must use native dialog semantics');
}

$interactive = $read('frontend/components/interactive.js');
foreach (['data-ui-drawer-open', 'data-ui-drawer-close', 'HTMLDialogElement', 'showModal()', '.close()'] as $needle) {
    $contains($interactive, $needle, 'Drawer browser behavior is incomplete');
}

$agents = $read('app/Interfaces/Web/View/sales_admin/agents.phtml');
$contains($agents, "components/ui/data_table", 'Sales agents list must prove canonical DataTable on real data');
if (str_contains($agents, 'style="')) {
    throw new RuntimeException('Canonical Sales agents migration must not reintroduce inline styling');
}

$agent = $read('app/Interfaces/Web/View/sales_admin/agent.phtml');
$contains($agent, "components/ui/entity_header", 'Sales agent detail must prove canonical EntityHeader on a real entity workspace');

$docs = $read('docs/architecture/web-v0.16.md');
foreach (['PageHeader', 'EntityHeader', 'Panel', 'DataTable', 'FilterBar', 'ActionBar', 'Drawer', 'Status / Stage', 'Breadcrumbs', 'WCAG 2.2 AA', 'Server-first', 'Definition of Done'] as $needle) {
    $contains($docs, $needle, 'WEB V0.16 documentation is incomplete');
}

echo "WEB V0.16 canonical component system passed.\n";
