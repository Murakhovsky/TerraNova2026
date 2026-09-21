<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Action/UIAction.php',
    'symfony/src/Web/Experience/Action/UIActionIntent.php',
    'symfony/src/Web/Experience/Action/UIActionPlacement.php',
    'symfony/src/Web/Experience/Action/UIActionDangerLevel.php',
    'symfony/src/Web/Experience/Action/UIActionConfirmation.php',
    'symfony/src/Web/Experience/Action/UIActionPermissionDecision.php',
    'symfony/src/Web/Experience/Action/UIActionPermissionCheckerInterface.php',
    'symfony/src/Web/Experience/Action/UIActionPermissionResolver.php',
    'symfony/src/Web/Experience/Action/UIActionRegistry.php',
    'symfony/src/Web/Experience/Action/UIActionResolver.php',
    'symfony/src/Web/Sales/SalesUIActionPermissionChecker.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.6 action platform artifact is missing: ' . $relative);
    }
}

$action = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIAction.php');
foreach ([
    'UIActionConfirmation|string|null',
    'UIActionDangerLevel::tryFrom',
    'confirmationContract()',
    'supportsPlacement(',
    'withAvailability(',
    'Critical UIAction requires step-up confirmation.',
] as $contract) {
    if (!str_contains($action, $contract)) {
        throw new RuntimeException('UIAction canonical contract is missing: ' . $contract);
    }
}

$placements = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIActionPlacement.php');
foreach ([
    "MOBILE_PRIMARY = 'mobile.primary'",
    "MOBILE_MENU = 'mobile.menu'",
    "DATA_GRID_ROW = 'datagrid.row'",
    "AI_PROPOSAL = 'ai_proposal'",
    "NOTIFICATION = 'notification'",
] as $contract) {
    if (!str_contains($placements, $contract)) {
        throw new RuntimeException('UIAction placement contract is missing: ' . $contract);
    }
}

$resolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIActionResolver.php');
foreach ([
    'UIActionRegistry',
    'UIActionPermissionResolver',
    'organizationId()->value() !== $context->organizationId',
    'role()->value() !== $context->role',
    'supportsPlacement($placement)',
    'withAvailability(false',
] as $contract) {
    if (!str_contains($resolver, $contract)) {
        throw new RuntimeException('UIAction resolver invariant is missing: ' . $contract);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIActionRegistry.php');
foreach ([
    'WebExtensionCatalog',
    'forContext($context)->actions($entity)',
    'Duplicate UIAction id',
    '[$left->priority, $left->id]',
] as $contract) {
    if (!str_contains($registry, $contract)) {
        throw new RuntimeException('UIAction registry invariant is missing: ' . $contract);
    }
}

$permissionResolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIActionPermissionResolver.php');
foreach ([
    'UIActionPermissionCheckerInterface',
    'checker->supports($permission)',
    'No UI permission checker is registered for this action.',
] as $contract) {
    if (!str_contains($permissionResolver, $contract)) {
        throw new RuntimeException('UIAction permission resolver contract is missing: ' . $contract);
    }
}

$manifest = (string) file_get_contents($root . '/app/Domains/Sales/module.php');
if (!str_contains($manifest, "'web.actions' => ['salesNavigationContributor']")) {
    throw new RuntimeException('Sales does not own its canonical web.actions contribution.');
}

$salesProvider = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php',
);
foreach ([
    'ActionProviderInterface',
    'public function actions(',
    "'sales.change_stage'",
    "'sales.assign_owner'",
    "'sales.request_document'",
    "'sales.create_lead_followup_task'",
    'UIActionPlacement::MOBILE_PRIMARY',
    'UIActionPlacement::MOBILE_MENU',
    'UIActionDangerLevel::Caution',
] as $contract) {
    if (!str_contains($salesProvider, $contract)) {
        throw new RuntimeException('Sales UIAction contribution is missing: ' . $contract);
    }
}
foreach (['use Domains\\', 'Doctrine\\', 'PDO', 'HttpClientInterface', '/api/v1/', 'fetch('] as $forbidden) {
    if (str_contains($salesProvider, $forbidden)) {
        throw new RuntimeException('Sales Web Experience provider leaked forbidden dependency: ' . $forbidden);
    }
}

$salesPermission = (string) file_get_contents(
    $root . '/symfony/src/Web/Sales/SalesUIActionPermissionChecker.php',
);
foreach ([
    'SalesAccessControlInterface',
    'SalesCapability::values()',
    '$tenant->isAdmin()',
    'hasCapability(',
] as $contract) {
    if (!str_contains($salesPermission, $contract)) {
        throw new RuntimeException('Sales UIAction permission adapter is missing: ' . $contract);
    }
}
foreach (['PDO', 'Doctrine\\', 'MysqlSalesAccessControl'] as $forbidden) {
    if (str_contains($salesPermission, $forbidden)) {
        throw new RuntimeException('Sales UIAction permission adapter bypasses Application contract: ' . $forbidden);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'cos.web.action.permission_checker',
    'UIActionPermissionCheckerInterface:',
    'UIActionPermissionResolver:',
    '!tagged_iterator cos.web.action.permission_checker',
] as $contract) {
    if (!str_contains($services, $contract)) {
        throw new RuntimeException('UIAction DI wiring is missing: ' . $contract);
    }
}

$experienceDir = $root . '/symfony/src/Web/Experience/Action';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($experienceDir));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    foreach (['use Domains\\', 'Doctrine\\', 'PDO', 'HttpClientInterface', '/api/v1/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Unified UIAction platform crossed an architecture boundary in %s: %s',
                $file->getFilename(),
                $forbidden,
            ));
        }
    }
}

echo "Wave 12.6 Unified Action Platform passed.\n";
