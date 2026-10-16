<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$controller = (string)file_get_contents($root.'/symfony/assets/controllers/engineering_live_controller.js');
if (preg_match('/const UI\\s*=\\s*\\{[\\s\\S]*?\\};/m', $controller, $m) !== 1) {
    throw new RuntimeException('Locale dictionary declaration not found.');
}
if (str_contains($m[0], 'this.ui()') || str_contains($m[0], 'this.')) {
    throw new RuntimeException('ES module top-level locale dictionary references instance context. Import crashes before Stimulus bootstrap.');
}
$tpl = (string)file_get_contents($root.'/symfony/templates/experience/workspace_shell.html.twig');
if (!str_contains($tpl,'data-action="click->workspace-shell#openNotifications"')
    || str_contains($tpl,"#open{{ 'shell.notifications'|trans }}")) {
    throw new RuntimeException('Stimulus notification action name must not be translated.');
}
echo "Locale ES-module initialization and stable Stimulus action contract passed.\\n";
