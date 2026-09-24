<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing canonical Property artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};

$controller = $read('symfony/src/Web/Property/PropertyPageController.php');
$inventoryController = $read('symfony/src/Web/Property/PropertyInventoryController.php');
foreach (['final readonly class PropertyPageController', "public function favour(Request \$request): Response", "'property/favour'"] as $needle) {
    $contains($controller, $needle, 'Canonical Symfony public Property controller is incomplete');
}
foreach (['GetPropertyInventoryCollectionQuery','PageArchetype::Collection','DataGridQuery','PropertyInventoryPresenter'] as $needle) {
    $contains($inventoryController,$needle,'Canonical Property Inventory controller is incomplete');
}
foreach ([
    "'property/manage'",
    "'property/listing'",
    "'property/add'",
    "'property/edit'",
    "'property/group'",
    "'property/submission'",
    "'property/pdf'",
] as $legacyView) {
    $notContains($controller, "html(\$request, " . $legacyView, 'Canonical runtime must not render retired Property compatibility views');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /property/catalog',
    'path: /property/map',
    'path: /property/favour',
    'PropertyPageController::favour',
    'PropertyInventoryController::manage',
    'PropertyInventoryController::listing',
    'PropertySubmissionsController::index',
    'PropertySubmissionController::index',
    'PropertyMapController::index',
    'path: /property/show/{slug}',
    'path: /property/presentation/{slug}',
    'path: /property/submit',
    'path: /property/create',
    'PropertyPageController::submit',
    'path: /property/manage',
    'path: /property/listing',
    'path: /property/submissions',
    'path: /property/submission/{id}',
] as $needle) {
    $contains($routes, $needle, 'Canonical Property route contract is incomplete');
}

$workspace = $read('symfony/templates/experience/property/inventory.html.twig');
foreach (['<twig:CosPageHeader','<twig:CosToolbar','<twig:CosDataGrid','data-property-inventory'] as $needle) {
    $contains($workspace,$needle,'Canonical Property inventory/listing workspace is incomplete');
}
if (is_file($root . '/app/Interfaces/Web/View/property/workspace_canonical.phtml')) throw new RuntimeException('Legacy Property inventory PHTML restored.');

$submissions=$read('symfony/templates/experience/property/submissions.html.twig');
foreach(['<twig:CosPageHeader','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:CosEntityListItem'] as $needle){$contains($submissions,$needle,'Canonical Property submissions queue is incomplete');}
$submissionsPresenter=$read('symfony/src/Web/Property/PropertySubmissionsPresenter.php');
$contains($submissionsPresenter,"'/property/submission/'",'Property submissions deep-link contract is incomplete');
if(is_file($root.'/app/Interfaces/Web/View/property/submissions.phtml'))throw new RuntimeException('Legacy Property submissions PHTML restored.');

$submission=$read('symfony/templates/experience/property/submission.html.twig');
foreach(['<twig:CosWorkspace','<twig:CosEntityHeader','class="cos-kpi-strip"','property/submissions','data-property-submission'] as $needle){$contains($submission,$needle,'Canonical Property submission detail is incomplete');}
if(is_file($root.'/app/Interfaces/Web/View/property/submission_canonical.phtml'))throw new RuntimeException('Legacy Property submission PHTML restored.');

$favour = $read('app/Interfaces/Web/View/property/favour.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "'data-favourite-empty' => ''",
    'data-favourite-list',
    'data-favourite-item',
] as $needle) {
    $contains($favour, $needle, 'Property favourites surface lost its canonical or live-state contract');
}

$state = $read('app/Interfaces/Web/View/components/ui/state.phtml');
foreach ([
    '$attributes',
    'foreach ($attributes as $name => $value)',
] as $needle) {
    $contains($state, $needle, 'Canonical State must preserve generic attributes');
}

foreach ([
    'app/Interfaces/Web/View/property/manage.phtml',
    'app/Interfaces/Web/View/property/listing.phtml',
    'app/Interfaces/Web/View/property/add.phtml',
    'app/Interfaces/Web/View/property/edit.phtml',
    'app/Interfaces/Web/View/property/group.phtml',
    'app/Interfaces/Web/View/property/submission.phtml',
    'app/Interfaces/Web/View/property/create.phtml',
    'app/Interfaces/Web/View/property/compare.phtml',
] as $retiredView) {
    if (is_file($root . '/' . $retiredView)) {
        throw new RuntimeException('Retired Property compatibility view restored: ' . $retiredView);
    }
}

$pdfService = $read('app/Domains/Property/Infrastructure/Presentation/PropertyPresentationService.php');
$contains($pdfService, 'property/pdf.phtml', 'Property PDF service renderer must remain available.');
$read('app/Interfaces/Web/View/property/pdf.phtml');

foreach (['frontend/entrypoints/property-workspace.js','frontend/features/property/workspace.css','frontend/features/property/workspace.js'] as $retiredFrontend) {
    if (is_file($root . '/' . $retiredFrontend)) throw new RuntimeException('Retired Property Workspace frontend restored: ' . $retiredFrontend);
}
$vite=$read('vite.config.js');
$notContains($vite,"'property-workspace'",'Retired Property Workspace Vite entry restored');

$navigation = $read('symfony/src/Web/Navigation/NavigationBuilder.php');
foreach (["'key' => 'properties'", "'key' => 'objects'", "'key' => 'listing'", "'key' => 'submissions'"] as $needle) {
    $contains($navigation, $needle, 'Canonical Property workspace navigation is incomplete');
}

echo "WEB V0.7 Property Workspace canonical runtime passed.\n";
