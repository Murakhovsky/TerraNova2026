<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Application/Identity/Query/GetUserAdministrationQuery.php',
    'symfony/src/Application/Identity/Query/GetUserAdministrationQueryHandler.php',
    'symfony/src/Web/Identity/UserAdministrationController.php',
    'symfony/src/Web/Identity/UserAdministrationMutationController.php',
    'symfony/src/Web/Identity/UserAdministrationPresenter.php',
    'symfony/src/Web/Identity/ViewModel/UserAdministrationViewModel.php',
    'symfony/src/Web/Identity/Component/IdentityUserAdministrationItem.php',
    'symfony/templates/experience/admin/users.html.twig',
    'symfony/templates/components/identity/user_administration_item.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-023 artifact missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/admin/users.phtml',
    'symfony/src/Web/Workspace/CoreWorkspacePageController.php',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('VR-023 retired source restored: ' . $retired);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Identity/UserAdministrationController.php');
foreach ([
    'GetUserAdministrationQuery',
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    "'Toolbar'",
    "'FilterBar'",
    "'DataGrid'",
    "'EntityList'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-023 read controller contract incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'AdministrationServiceInterface', 'Doctrine\\'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-023 read controller leaked forbidden dependency: ' . $forbidden);
    }
}

$mutation = (string) file_get_contents($root . '/symfony/src/Web/Identity/UserAdministrationMutationController.php');
foreach (['createUser(', 'updateUser(', 'SessionCsrfValidator', 'isAdmin()'] as $marker) {
    if (!str_contains($mutation, $marker)) {
        throw new RuntimeException('VR-023 mutation contract incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/admin/users.html.twig');
$item = (string) file_get_contents($root . '/symfony/templates/components/identity/user_administration_item.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosToolbar', '<twig:CosMetric', '<twig:CosFilterBar', '<twig:CosDataGrid', '<twig:IdentityUserAdministrationItem'] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-023 System Control Surface composition incomplete: ' . $marker);
    }
}
foreach (['/admin/updateUser/', 'name="csrf_token"', 'name="full_name"', 'name="role"', 'name="status"', 'name="password"'] as $marker) {
    if (!str_contains($item, $marker)) {
        throw new RuntimeException('VR-023 user mutation component incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script', '<table'] as $forbidden) {
    if (str_contains($template, $forbidden) || str_contains($item, $forbidden)) {
        throw new RuntimeException('VR-023 restored legacy/local presentation: ' . $forbidden);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
$systemStart = strpos($registry, 'PageArchetype::SystemControlSurface');
$systemSlice = $systemStart === false ? '' : substr($registry, $systemStart, 800);
if (!str_contains($systemSlice, "'FilterBar'")) {
    throw new RuntimeException('System Control Surface must explicitly support FilterBar after VR-023.');
}

echo "Wave 13 VR-023 /admin/users System Control Surface passed.\n";
