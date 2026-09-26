<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 10 Property artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /property',
    'path: /property/catalog',
    'PublicPropertyCatalogController::index',
    'path: /property/map',
    'path: /property/favour',
    'PropertyPageController::favour',
    'path: /property/show/{slug}',
    'path: /property/presentation/{slug}',
    'path: /property/pdf/{slug}',
    'path: /property/submit',
    'path: /property/create',
    'path: /submit-property',
    'path: /property/manage',
    'path: /property/listing',
    'path: /property/submissions',
    'path: /property/submission/{id}',
] as $marker) {
    $contains($routes, $marker, 'Canonical Property route contract is incomplete.');
}
foreach ([
    'path: /property/add',
    'path: /property/edit/{id}',
    'path: /property/group/{id}',
    'path: /property/compare',
] as $retiredRoute) {
    $notContains($routes, $retiredRoute, 'Retired Property compatibility route restored.');
}

$controller = $read('symfony/src/Web/Property/PropertyPageController.php');
foreach (["public function favour(Request \$request): Response","'property/favour'","new RedirectResponse('/property/presentation/'"] as $marker) {
    $contains($controller,$marker,'Canonical Symfony Property controller is incomplete.');
}
$inventoryController=$read('symfony/src/Web/Property/PropertyInventoryController.php');
foreach(['GetPropertyInventoryCollectionQuery','PageArchetype::Collection','DataGridQuery'] as $marker){$contains($inventoryController,$marker,'Property Inventory controller incomplete.');}
$submissionsController=$read('symfony/src/Web/Property/PropertySubmissionsController.php');
$submissionController=$read('symfony/src/Web/Property/PropertySubmissionController.php');
$mapController=$read('symfony/src/Web/Property/PropertyMapController.php');
foreach(['PageArchetype::OperationalQueue','GetPropertySubmissionsQueueQuery'] as $marker){$contains($submissionsController,$marker,'Property Submissions controller incomplete.');}
foreach(['PageArchetype::EntityWorkspace','GetPropertySubmissionWorkspaceQuery'] as $marker){$contains($submissionController,$marker,'Property Submission controller incomplete.');}
foreach(['PageArchetype::MapSpatial','GetPropertyMapQuery'] as $marker){$contains($mapController,$marker,'Property Map controller incomplete.');}

$workspace=$read('symfony/templates/experience/property/inventory.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosToolbar','<twig:CosDataGrid','data-property-inventory'] as $marker){$contains($workspace,$marker,'Canonical Property inventory/listing workspace is incomplete.');}
if(is_file($root.'/app/Interfaces/Web/View/property/workspace_canonical.phtml'))throw new RuntimeException('Legacy Property inventory PHTML restored.');

$submissions=$read('symfony/templates/experience/property/submissions.html.twig');
foreach(['<twig:CosPageHeader','class="cos-kpi-strip"','<twig:CosFilterBar','<twig:CosEntityListItem'] as $marker){$contains($submissions,$marker,'Canonical Property submissions queue is incomplete.');}
$submissionsPresenter=$read('symfony/src/Web/Property/PropertySubmissionsPresenter.php');
$contains($submissionsPresenter,"'/property/submission/'",'Property submissions deep-link contract is incomplete.');
if(is_file($root.'/app/Interfaces/Web/View/property/submissions.phtml'))throw new RuntimeException('Legacy Property submissions PHTML restored.');

$submission=$read('symfony/templates/experience/property/submission.html.twig');
foreach(['<twig:CosWorkspace','<twig:CosEntityHeader','class="cos-kpi-strip"','property/submissions','data-property-submission'] as $marker){$contains($submission,$marker,'Canonical Property submission detail is incomplete.');}
if(is_file($root.'/app/Interfaces/Web/View/property/submission_canonical.phtml'))throw new RuntimeException('Legacy Property submission PHTML restored.');

$catalog=$read('symfony/templates/experience/public/property_catalog.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosFilterBar','cos-property-catalog-grid','data-controller="public-property"','application/ld+json'] as $marker){
    $contains($catalog,$marker,'Canonical Public Property Catalog lost behavior/presentation contract.');
}
foreach(['tn-','style=','onclick='] as $forbidden){
    $notContains($catalog,$forbidden,'Canonical Public Property Catalog restored legacy/local presentation.');
}
if(is_file($root.'/app/Interfaces/Web/View/property/catalog.phtml'))throw new RuntimeException('Legacy Property Catalog PHTML restored.');


$detail=$read('symfony/templates/experience/public/property_detail.html.twig');
foreach([
    '<twig:CosPageHeader',
    'data-controller="public-property public-property-gallery"',
    'data-public-property-gallery-target="main"',
    'data-public-property-gallery-target="thumb"',
    'data-public-property-target="button"',
    'data-public-property-target="intent"',
    'application/ld+json',
    'id="request"',
    'id="related"',
] as $marker){
    $contains($detail,$marker,'Canonical Public Property Detail lost behavior/presentation contract.');
}
foreach(['tn-','style=','onclick='] as $forbidden){
    $notContains($detail,$forbidden,'Canonical Public Property Detail restored legacy/local presentation.');
}
if(is_file($root.'/app/Interfaces/Web/View/property/show.phtml'))throw new RuntimeException('Legacy Property Detail PHTML restored.');

foreach ([
    'favour' => ['page_header', 'state', 'data-favourite-empty', 'data-favourite-list', 'data-favourite-item'],
    'presentation' => ['state', 'action_bar', 'data-request-intent', 'data-copy-value'],
    'seo' => ['page_header', 'state', 'itemscope itemtype="https://schema.org/Product"'],
    'submit' => ['page_header', 'state', 'enctype="multipart/form-data"', 'name="owner_name"', 'name="property_type"'],
] as $view => $markers) {
    $source = $read('app/Interfaces/Web/View/property/' . $view . '.phtml');
    foreach ($markers as $marker) {
        $needle = in_array($marker, ['page_header', 'state', 'action_bar'], true)
            ? "partial('components/ui/" . $marker . "'"
            : $marker;
        $contains($source, $needle, 'Property ' . $view . ' surface lost a canonical or behavior contract.');
    }
}

$state = $read('app/Interfaces/Web/View/components/ui/state.phtml');
foreach (['$attributes', 'foreach ($attributes as $name => $value)'] as $marker) {
    $contains($state, $marker, 'Canonical State must support generic DOM attributes.');
}

$favourJs = $read('frontend/features/public/interactions.js');
foreach (['data-favourite-empty', 'data-favourite-count', 'data-favourite-item', '/api/v1/public/properties/favourites'] as $marker) {
    $contains($favourJs, $marker, 'Favourites browser contract is incomplete.');
}

$map = $read('symfony/templates/experience/property/map.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosContextPanel',
    '<twig:CosEntityListItem',
    'data-controller="property-map"',
    'data-property-map',
] as $marker) {
    $contains($map, $marker, 'Canonical Property map surface is incomplete.');
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    $notContains($map, $forbidden, 'Property map must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/property/map.phtml')) {
    throw new RuntimeException('Legacy Property map PHTML restored.');
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
$contains($pdfService, "property/pdf.phtml", 'Property PDF service renderer must remain available outside the web route renderer.');
$read('app/Interfaces/Web/View/property/pdf.phtml');

$docs = $read('docs/03-architecture/cos-production-property-adoption.md');
foreach ([
    '# Впровадження Property у production UI',
    '## Хвиля 8',
    '### Закриття route/view debt',
    '### Вибране (Favourites) route closure',
    '### Виведені compatibility views',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Property production adoption closure documentation is incomplete.');
}

echo "PHASE 10 Property production adoption closure passed.\n";
