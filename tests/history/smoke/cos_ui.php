<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$template = (string) file_get_contents($root . '/symfony/templates/experience/system/control_center.html.twig');
$presenter = (string) file_get_contents($root . '/symfony/src/Web/Operations/ControlCenterPresenter.php');

foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'Execute',
    'Approve',
    'Reject',
    'csrf_token',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('COS Control Center smoke contract missing: ' . $marker);
    }
}
foreach (['Events', 'Decisions', 'Proposed Actions', 'Approvals', 'Results', 'Audit'] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('COS Control Center presenter section missing: ' . $marker);
    }
}
foreach (['|raw', 'tn-', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('COS Control Center restored unsafe/legacy presentation: ' . $forbidden);
    }
}

echo "COS Control Center Twig smoke test passed.\n";
