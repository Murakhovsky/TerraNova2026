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
foreach ([
    'final readonly class PropertyPageController',
    "return \$this->inventoryWorkspace(\$request, 'Inventory'",
    "return \$this->inventoryWorkspace(\$request, 'Listing'",
    "'property/workspace_canonical'",
    "'property/submissions'",
    "'property/submission_canonical'",
    "public function favour(Request \$request): Response",
    "'property/favour'",
] as $needle) {
    $contains($controller, $needle, 'Canonical Symfony Property controller is incomplete');
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

$workspace = $read('app/Interfaces/Web/View/property/workspace_canonical.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/data_table'",
    'tn-property-workspace',
] as $needle) {
    $contains($workspace, $needle, 'Canonical Property inventory/listing workspace is incomplete');
}

$submissions = $read('app/Interfaces/Web/View/property/submissions.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/data_table'",
    'property/submission/',
] as $needle) {
    $contains($submissions, $needle, 'Canonical Property submissions queue is incomplete');
}

$submission = $read('app/Interfaces/Web/View/property/submission_canonical.phtml');
foreach ([
    "partial('components/ui/page_header'",
    'Read-only canonical intake view',
    'property/submissions',
] as $needle) {
    $contains($submission, $needle, 'Canonical Property submission detail is incomplete');
}

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
    'app/Interfaces/Web/View/property/pdf.phtml',
] as $retiredView) {
    if (is_file($root . '/' . $retiredView)) {
        throw new RuntimeException('Retired Property compatibility view restored: ' . $retiredView);
    }
}

$entrypoint = $read('frontend/entrypoints/property-workspace.js');
$contains($entrypoint, "../features/property/workspace.css", 'Property workspace Vite entrypoint lost CSS');
$contains($entrypoint, "../features/property/workspace.js", 'Property workspace Vite entrypoint lost JS');

$workspaceJs = $read('frontend/features/property/workspace.js');
foreach (["dataset.propertyWorkspace = 'true'", "addEventListener('submit'", 'aria-busy'] as $needle) {
    $contains($workspaceJs, $needle, 'Property Workspace progressive enhancement is incomplete');
}

$navigation = $read('symfony/src/Web/Navigation/NavigationBuilder.php');
foreach (["'key' => 'properties'", "'key' => 'objects'", "'key' => 'listing'", "'key' => 'submissions'"] as $needle) {
    $contains($navigation, $needle, 'Canonical Property workspace navigation is incomplete');
}

echo "WEB V0.7 Property Workspace canonical runtime passed.\n";
