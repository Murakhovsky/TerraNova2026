<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/symfony/templates/experience/growth/workspace.html.twig');

foreach ([
    "v == 'growth/dashboard'",
    '<twig:CosPageHeader',
    '<twig:CosMetric',
    '<twig:CosToolbar',
    '<twig:CosEntityListItem',
    '<twig:CosEmptyState',
    'Recently updated opportunities',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Growth Golden overview missing canonical marker: ' . $marker);
    }
}

$legacyDashboard = strstr($template, "{% elseif v=='growth/dashboard' %}");
$legacyDashboard = $legacyDashboard === false ? '' : explode("{% elseif v=='growth/accounts' %}", $legacyDashboard, 2)[0];
foreach (['cos-growth-kpis', 'cos-growth-kpi', 'cos-growth-row'] as $legacy) {
    if (str_contains($legacyDashboard, $legacy)) {
        throw new RuntimeException('Growth Golden overview still depends on legacy presentation primitive: ' . $legacy);
    }
}

echo "Growth Golden overview contract OK\n";
