<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sales = $root . '/app/Domains/Sales';

foreach (['Model', 'Application/Contract', 'Application/DTO', 'Application/UseCase', 'Automation/Event',
             'Automation/Rule', 'Automation/Agent', 'Automation/Action', 'Automation/Job',
             'Automation/Policy', 'Infrastructure/Persistence', 'Infrastructure/ReadModel', 'Bootstrap'] as $area) {
    if (!is_dir($sales . '/' . $area)) {
        throw new RuntimeException('Sales reference structure is missing: ' . $area);
    }
}
if (!is_file($sales . '/README.md')) {
    throw new RuntimeException('Sales development guide is missing.');
}

$coreIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sales));
foreach ($coreIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sales) + 1));
    if (!preg_match('~^(Model|Application|Automation|Bootstrap)/~', $relative)) continue;
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('/\b(TODO|FIXME|NotImplemented)\b/', $source)) {
        throw new RuntimeException('Unfinished Sales core code: ' . $relative);
    }
    foreach (['Longman\\TelegramBot', 'Infrastructure\\Integration\\Telegram', 'Interfaces\\Telegram'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('Sales core depends on Telegram delivery: ' . $relative);
        }
    }
}

$salesServices = (string) file_get_contents($root . '/app/Bootstrap/SalesServices.php');
$webServices = (string) file_get_contents($root . '/app/Bootstrap/WebApplicationServices.php');
foreach (['salesCreateClientCase', 'salesUpdateClientCase', 'salesQuickUpdateClientCase',
             'salesReceivePublicLead', 'salesReceiveCrmWebhook', 'salesProcessCrmInbox'] as $serviceId) {
    if (!str_contains($salesServices, "'" . $serviceId . "'")) {
        throw new RuntimeException('Sales composition root is missing service: ' . $serviceId);
    }
    if (str_contains($webServices, "setShared('" . $serviceId . "'")) {
        throw new RuntimeException('Sales service is incorrectly scoped to Web: ' . $serviceId);
    }
}

echo "Sales readiness passed: structure, core completeness, delivery isolation and shared composition are enforced.\n";
