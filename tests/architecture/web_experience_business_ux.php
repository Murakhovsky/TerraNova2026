<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$components = [
    'CosEntityHeader',
    'CosEntitySummary',
    'CosEntityCard',
    'CosEntityListItem',
    'CosActionBar',
    'CosBulkActionBar',
    'CosFilterBar',
    'CosStage',
    'CosOwner',
    'CosNextAction',
    'CosRelations',
    'CosTimeline',
    'CosActivityFeed',
    'CosMoneyMetric',
    'CosTrendMetric',
];

foreach ($components as $component) {
    $class = $root . '/symfony/src/Web/Experience/Component/' . $component . '.php';
    if (!is_file($class)) {
        throw new RuntimeException('PHASE 7 component is missing: ' . $component);
    }

    $source = (string) file_get_contents($class);
    foreach (['Domains\\', 'Doctrine\\', '/api/', 'Repository'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'PHASE 7 component %s contains forbidden business/data dependency: %s',
                $component,
                $forbidden,
            ));
        }
    }
}

foreach ([
    'cos_entity_header.html.twig',
    'cos_entity_summary.html.twig',
    'cos_entity_card.html.twig',
    'cos_entity_list_item.html.twig',
    'cos_action_bar.html.twig',
    'cos_bulk_action_bar.html.twig',
    'cos_filter_bar.html.twig',
    'cos_stage.html.twig',
    'cos_owner.html.twig',
    'cos_next_action.html.twig',
    'cos_relations.html.twig',
    'cos_timeline.html.twig',
    'cos_activity_feed.html.twig',
    'cos_money_metric.html.twig',
    'cos_trend_metric.html.twig',
] as $template) {
    if (!is_file($root . '/symfony/templates/components/experience/' . $template)) {
        throw new RuntimeException('PHASE 7 template is missing: ' . $template);
    }
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($appCss, "@import './business-patterns.css';")) {
    throw new RuntimeException('PHASE 7 business-patterns.css is not loaded.');
}

$css = (string) file_get_contents($root . '/symfony/assets/styles/business-patterns.css');
foreach ([
    '.cos-entity-header',
    '.cos-entity-summary',
    '.cos-entity-card',
    '.cos-entity-list-item',
    '.cos-action-bar',
    '.cos-bulk-action-bar',
    '.cos-filter-bar',
    '.cos-stage--current',
    '.cos-owner',
    '.cos-next-action',
    '.cos-relation',
    '.cos-timeline',
    '.cos-activity-feed',
    '.cos-money-metric',
    '.cos-trend-metric',
    '@media (max-width: 760px)',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('PHASE 7 business UX CSS is incomplete: ' . $marker);
    }
}

$stage = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/CosStage.php');
foreach (['complete', 'current', 'upcoming', 'blocked'] as $state) {
    if (!str_contains($stage, "'$state'")) {
        throw new RuntimeException('CosStage workflow state contract is incomplete: ' . $state);
    }
}

$moneyTemplate = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_money_metric.html.twig');
$trendTemplate = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_trend_metric.html.twig');
if (!str_contains($moneyTemplate, 'cos-money') || !str_contains($trendTemplate, 'cos-numeric')) {
    throw new RuntimeException('PHASE 7 financial patterns do not consume the canonical numeric contract.');
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'PHASE 7 Entity + Business UX',
    '<twig:CosEntityHeader',
    '<twig:CosEntitySummary',
    '<twig:CosStage',
    '<twig:CosOwner',
    '<twig:CosNextAction',
    '<twig:CosRelations',
    '<twig:CosTimeline',
    '<twig:CosActivityFeed',
    '<twig:CosMoneyMetric',
    '<twig:CosTrendMetric',
    '<twig:CosFilterBar',
    '<twig:CosBulkActionBar',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('/dev/ui PHASE 7 reference surface is incomplete: ' . $marker);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
foreach ($components as $component) {
    if (!str_contains($registry, "entry('$component'")) {
        throw new RuntimeException('PHASE 7 component is missing from UI Catalog: ' . $component);
    }
}

$docs = (string) file_get_contents($root . '/docs/03-architecture/cos-business-ux.md');
foreach ([
    'Сутність як основна одиниця роботи',
    'Етапи, відповідальність і наступна дія',
    'Зв’язки та активність',
    'Фінансова мова',
    'Критерії завершення PHASE 7',
] as $marker) {
    if (!str_contains($docs, $marker)) {
        throw new RuntimeException('PHASE 7 documentation is incomplete: ' . $marker);
    }
}

echo "PHASE 7 COS Entity + Business UX passed.\n";
