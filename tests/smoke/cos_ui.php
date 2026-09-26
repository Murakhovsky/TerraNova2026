<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('COS UI smoke artifact is missing: ' . $relative);
    return (string) file_get_contents($path);
};

$template = $read('symfony/templates/experience/operations/control_center.html.twig');
$presenter = $read('symfony/src/Web/Operations/ControlCenterPresenter.php');
$controller = $read('symfony/src/Web/Operations/ControlCenterPageController.php');

foreach (['Events','Decisions','Proposed Actions','Approvals','Results','Audit','Execute','Approve','Reject','/cos/action/','/cos/approval/'] as $expected) {
    if (!str_contains($template, $expected)) throw new RuntimeException('COS canonical UI is missing: ' . $expected);
}
foreach (["'events'","'decisions'","'results'","'audit'",'reason'] as $expected) {
    if (!str_contains($presenter, $expected)) throw new RuntimeException('COS presenter is missing runtime projection: ' . $expected);
}
foreach (['OperationsMutationCommand::EXECUTE_ACTION','OperationsMutationCommand::APPROVE','OperationsMutationCommand::REJECT','SessionCsrfValidator'] as $expected) {
    if (!str_contains($controller, $expected)) throw new RuntimeException('COS governed mutation path is missing: ' . $expected);
}
foreach (['|raw','tn-','style=','<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) throw new RuntimeException('COS canonical UI contains unsafe/legacy presentation: ' . $forbidden);
}
if (is_file($root . '/app/Interfaces/Web/View/cos/index.phtml')) throw new RuntimeException('Legacy COS Control Center PHTML returned.');
echo "COS canonical Control Center smoke test passed.\n";
