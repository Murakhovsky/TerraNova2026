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
foreach ([
    "public function favour(Request \$request): Response",
    "'property/favour'",
    "'property/workspace_canonical'",
    "'property/submissions'",
    "'property/submission_canonical'",
    "new RedirectResponse('/property/presentation/'",
] as $marker) {
    $contains($controller, $marker, 'Canonical Symfony Property controller is incomplete.');
}

$workspace = $read('app/Interfaces/Web/View/property/workspace_canonical.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/data_table'",
    'tn-property-workspace',
] as $marker) {
    $contains($workspace, $marker, 'Canonical Property inventory/listing workspace is incomplete.');
}

$submissions = $read('app/Interfaces/Web/View/property/submissions.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    "partial('components/ui/data_table'",
    'property/submission/',
] as $marker) {
    $contains($submissions, $marker, 'Canonical Property submissions queue is incomplete.');
}

$submission = $read('app/Interfaces/Web/View/property/submission_canonical.phtml');
foreach ([
    "partial('components/ui/page_header'",
    'Read-only canonical intake view',
    'property/submissions',
] as $marker) {
    $contains($submission, $marker, 'Canonical Property submission detail is incomplete.');
}

foreach ([
    'catalog' => ['page_header', 'data-catalog-form', 'data-catalog-count'],
    'map' => ['page_header', 'state', 'tn-map-canvas', 'tn-map-pin'],
    'favour' => ['page_header', 'state', 'data-favourite-empty', 'data-favourite-list', 'data-favourite-item'],
    'show' => ['state', 'action_bar', 'data-request-intent', 'data-save-property', 'data-property-gallery'],
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
    '### Retired compatibility views',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'Property production adoption closure documentation is incomplete.');
}

echo "PHASE 10 Property production adoption closure passed.\n";
