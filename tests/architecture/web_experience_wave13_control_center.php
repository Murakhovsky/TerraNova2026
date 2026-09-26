<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach (['symfony/src/Application/Operations/Query/GetControlCenterOverviewQuery.php','symfony/src/Application/Operations/Query/GetControlCenterOverviewQueryHandler.php','symfony/src/Web/Operations/ControlCenterPageController.php','symfony/src/Web/Operations/ControlCenterPresenter.php','symfony/src/Web/Operations/ViewModel/ControlCenterViewModel.php','symfony/templates/experience/operations/control_center.html.twig'] as $relative) {
    if (!is_file($root . '/' . $relative)) throw new RuntimeException('VR-019 artifact is missing: ' . $relative);
}
foreach (['app/Interfaces/Web/View/cos/index.phtml','frontend/entrypoints/cos-control-center.js','frontend/features/cos/control-center.css'] as $legacy) {
    if (is_file($root . '/' . $legacy)) throw new RuntimeException('VR-019 retired presentation returned: ' . $legacy);
}
$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, 'cos-control-center')) throw new RuntimeException('VR-019 retired cos-control-center Vite entrypoint returned.');
$query = (string) file_get_contents($root . '/symfony/src/Application/Operations/Query/GetControlCenterOverviewQueryHandler.php');
foreach (['OperationsReadModelInterface','->overview('] as $marker) if (!str_contains($query, $marker)) throw new RuntimeException('VR-019 Application Query contract is incomplete: ' . $marker);
foreach (['App\\Web\\','Twig','PhtmlRenderer'] as $forbidden) if (str_contains($query, $forbidden)) throw new RuntimeException('VR-019 Application Query leaked presentation dependency: ' . $forbidden);
$controller = (string) file_get_contents($root . '/symfony/src/Web/Operations/ControlCenterPageController.php');
foreach (['QueryBusInterface','GetControlCenterOverviewQuery','PageArchetype::SystemControlSurface','WorkspaceShellFactory','PagePresentationFactory','ControlCenterPresenter','OperationsMutationCommand::EXECUTE_ACTION','OperationsMutationCommand::APPROVE','OperationsMutationCommand::REJECT','SessionCsrfValidator'] as $marker) if (!str_contains($controller, $marker)) throw new RuntimeException('VR-019 controller contract is incomplete: ' . $marker);
foreach (['PhtmlRenderer','NavigationBuilder','OperationsReadModelInterface'] as $forbidden) if (str_contains($controller, $forbidden)) throw new RuntimeException('VR-019 controller retained legacy/direct read ownership: ' . $forbidden);
$template = (string) file_get_contents($root . '/symfony/templates/experience/operations/control_center.html.twig');
foreach (["extends 'experience/workspace_shell.html.twig'",'<twig:CosPageHeader','<twig:CosToolbar','class="cos-kpi-strip"','<twig:CosMetric','<twig:CosEntityListItem','<twig:CosActionBar','<twig:CosEmptyState','id="{{ group.key }}"','id="actions"','id="approvals"','id="audit"','/cos/action/','/cos/approval/','name="csrf_token"','name="return_url"','data-cos-archetype'] as $marker) if (!str_contains($template, $marker)) throw new RuntimeException('VR-019 System Control Surface composition is incomplete: ' . $marker);
foreach (['tn-','style=','<script','<table','|raw'] as $forbidden) if (str_contains($template, $forbidden)) throw new RuntimeException('VR-019 restored legacy/unsafe presentation: ' . $forbidden);
$presenter = (string) file_get_contents($root . '/symfony/src/Web/Operations/ControlCenterPresenter.php');
foreach (["'events'", "'rules'", "'agents'", "'policies'", "'integrations'", "'decisions'", "'results'", "'audit'"] as $marker) {
    if (!str_contains($presenter, $marker)) {
        throw new RuntimeException('VR-019 presenter lost runtime group anchor: ' . $marker);
    }
}

$tracker = (string) file_get_contents($root . '/docs/03-architecture/wave13-migration-tracker.md');
if (!str_contains($tracker, 'VR-019') || !str_contains($tracker, '/cos/control-center') || !str_contains($tracker, '| QA |')) throw new RuntimeException('VR-019 migration tracker is not in QA.');
echo "Wave 13 VR-019 COS Control Center passed.\n";
