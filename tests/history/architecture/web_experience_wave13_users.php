<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

foreach([
 'symfony/src/Application/Administration/Query/GetAdministrationUsersQueryHandler.php',
 'symfony/src/Application/Administration/Command/CreateAdministrationUserCommandHandler.php',
 'symfony/src/Application/Administration/Command/UpdateAdministrationUserCommandHandler.php',
 'symfony/src/Web/Administration/AdministrationUsersController.php',
 'symfony/src/Web/Administration/AdministrationUsersPresenter.php',
 'symfony/src/Web/Administration/ViewModel/AdministrationUsersViewModel.php',
 'symfony/templates/experience/administration/users.html.twig',
] as $file){
 if(!is_file($root.'/'.$file)) throw new RuntimeException('VR-023 artifact missing: '.$file);
}
foreach([
 'app/Interfaces/Web/View/admin/users.phtml',
 'symfony/src/Web/Workspace/CoreWorkspacePageController.php',
] as $legacy){
 if(file_exists($root.'/'.$legacy)) throw new RuntimeException('VR-023 retired artifact restored: '.$legacy);
}
$controller=(string)file_get_contents($root.'/symfony/src/Web/Administration/AdministrationUsersController.php');
foreach(['GetAdministrationUsersQuery','CreateAdministrationUserCommand','UpdateAdministrationUserCommand','QueryBusInterface','CommandBusInterface','PageArchetype::SystemControlSurface','SessionCsrfValidator'] as $marker){
 if(!str_contains($controller,$marker)) throw new RuntimeException('VR-023 controller incomplete: '.$marker);
}
foreach(['PhtmlRenderer','NavigationBuilder','AdministrationServiceInterface'] as $forbidden){
 if(str_contains($controller,$forbidden)) throw new RuntimeException('VR-023 Web controller leaked forbidden dependency: '.$forbidden);
}
$template=(string)file_get_contents($root.'/symfony/templates/experience/administration/users.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosToolbar','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:CosDataGrid','<twig:CosEntityListItem','<twig:CosActionBar','action="/admin/createUser"','action="/admin/updateUser/{{ user.id }}"'] as $marker){
 if(!str_contains($template,$marker)) throw new RuntimeException('VR-023 composition incomplete: '.$marker);
}
foreach(['tn-','style=','<script','<table'] as $forbidden){
 if(str_contains($template,$forbidden)) throw new RuntimeException('VR-023 restored legacy/local presentation: '.$forbidden);
}
$registry=(string)file_get_contents($root.'/symfony/src/Web/Experience/Archetype/PageArchetypeRegistry.php');
if(!str_contains($registry,"['KpiStrip', 'FilterBar', 'ActivityFeed'")) throw new RuntimeException('System Control Surface must allow FilterBar for governed admin collections.');
echo "Wave 13 VR-023 Users Administration passed.\n";
