<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Property/Query/GetPublicPropertyDetailQuery.php',
    'symfony/src/Application/Property/Query/GetPublicPropertyDetailQueryHandler.php',
    'symfony/src/Application/Property/Command/RecordPublicPropertyViewCommand.php',
    'symfony/src/Application/Property/Command/RecordPublicPropertyViewCommandHandler.php',
    'symfony/src/Web/Property/PublicPropertyDetailController.php',
    'symfony/src/Web/Property/PublicPropertyDetailPresenter.php',
    'symfony/src/Web/Property/ViewModel/PublicPropertyDetailViewModel.php',
    'symfony/templates/experience/public/property_detail.html.twig',
    'symfony/assets/controllers/public_property_gallery_controller.js',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-029 artifact is missing: ' . $relative);
    }
}

foreach ([
    'app/Interfaces/Web/View/property/show.phtml',
    'frontend/entrypoints/terranova-property-gallery.js',
] as $retired) {
    if (is_file($root . '/' . $retired)) {
        throw new RuntimeException('VR-029 retired artifact restored: ' . $retired);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['path: /property/show/{slug}', 'PublicPropertyDetailController::show'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-029 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Property/PublicPropertyDetailController.php');
foreach ([
    'GetPublicPropertyDetailQuery',
    'RecordPublicPropertyViewCommand',
    'ReceivePublicLeadCommand',
    'QueryBusInterface',
    'CommandBusInterface',
    'PageArchetype::PublicDetailMarketing',
    'PagePresentationFactory',
    'PublicPropertyDetailPresenter',
    "experience/public/property_detail.html.twig",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-029 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'PropertyCatalogInterface', 'SalesWriteServiceFactoryInterface'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-029 controller leaked direct legacy/domain dependency: ' . $forbidden);
    }
}

$legacy = (string) file_get_contents($root . '/symfony/src/Web/Property/PropertyPageController.php');
if (str_contains($legacy, 'public function show(')) {
    throw new RuntimeException('VR-029 left duplicate Property Detail ownership.');
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/public/property_detail.html.twig');
foreach ([
    '<twig:CosPageHeader',
    'data-controller="public-property public-property-gallery"',
    'data-public-property-gallery-target="main"',
    'data-public-property-gallery-target="thumb"',
    'data-public-property-target="button"',
    'data-public-property-target="intent"',
    'data-request-intent="viewing"',
    "components/property/public_property_card.html.twig",
    'application/ld+json',
    'id="request"',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-029 Public Detail composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', 'onclick='] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-029 restored legacy/local presentation: ' . $forbidden);
    }
}

$gallery = (string) file_get_contents($root . '/symfony/assets/controllers/public_property_gallery_controller.js');
foreach (['select(event)', 'mainTarget.src', 'openTarget.href', 'aria-pressed'] as $marker) {
    if (!str_contains($gallery, $marker)) {
        throw new RuntimeException('VR-029 gallery runtime is incomplete: ' . $marker);
    }
}
foreach (['innerHTML', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($gallery, $forbidden)) {
        throw new RuntimeException('VR-029 gallery runtime leaked forbidden browser ownership: ' . $forbidden);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (str_contains($vite, "'terranova-property-gallery'")) {
    throw new RuntimeException('VR-029 retired gallery Vite entry remains configured.');
}

echo "Wave 13 VR-029 /property/show/{slug} Public Detail passed.\n";
