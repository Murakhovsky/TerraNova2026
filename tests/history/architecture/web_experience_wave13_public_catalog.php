<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Property/Query/GetPublicPropertyCatalogQuery.php',
    'symfony/src/Application/Property/Query/GetPublicPropertyCatalogQueryHandler.php',
    'symfony/src/Application/Sales/Command/ReceivePublicLeadCommand.php',
    'symfony/src/Application/Sales/Command/ReceivePublicLeadCommandHandler.php',
    'symfony/src/Web/Property/PublicPropertyCatalogController.php',
    'symfony/src/Web/Property/PublicPropertyCatalogPresenter.php',
    'symfony/src/Web/Property/ViewModel/PublicPropertyCatalogViewModel.php',
    'symfony/templates/experience/public/property_catalog.html.twig',
    'symfony/templates/components/property/public_property_card.html.twig',
    'symfony/assets/controllers/public_property_controller.js',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-028 artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/property/catalog.phtml')) {
    throw new RuntimeException('VR-028 legacy property/catalog.phtml must stay retired.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'path: /property',
    'path: /property/catalog',
    'PublicPropertyCatalogController::index',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('VR-028 route contract is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Property/PublicPropertyCatalogController.php');
foreach ([
    'GetPublicPropertyCatalogQuery',
    'ReceivePublicLeadCommand',
    'QueryBusInterface',
    'CommandBusInterface',
    'PageArchetype::PublicCatalog',
    'PagePresentationFactory',
    'PublicPropertyCatalogPresenter',
    "experience/public/property_catalog.html.twig",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('VR-028 controller contract is incomplete: ' . $marker);
    }
}
foreach (['PhtmlRenderer', 'PropertyCatalogInterface', 'SalesWriteServiceFactoryInterface'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('VR-028 controller leaked direct legacy/domain dependency: ' . $forbidden);
    }
}

$legacy = (string) file_get_contents($root . '/symfony/src/Web/Property/PropertyPageController.php');
if (str_contains($legacy, 'public function catalog(')) {
    throw new RuntimeException('VR-028 left duplicate catalog ownership in PropertyPageController.');
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/public/property_catalog.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosFilterBar',
    'cos-property-catalog-grid',
    "components/property/public_property_card.html.twig",
    'data-controller="public-property"',
    'data-cos-public="property-catalog"',
    'data-cos-archetype',
    'action="/property/catalog#request"',
    'application/ld+json',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('VR-028 Public Catalog composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', 'onclick='] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('VR-028 restored legacy/local presentation: ' . $forbidden);
    }
}

$browser = (string) file_get_contents($root . '/symfony/assets/controllers/public_property_controller.js');
foreach ([
    '/api/v1/public/properties/favourites',
    'aria-pressed',
    'credentials: \'same-origin\'',
] as $marker) {
    if (!str_contains($browser, $marker)) {
        throw new RuntimeException('VR-028 favourites runtime is incomplete: ' . $marker);
    }
}
foreach (['localStorage', 'sessionStorage', 'innerHTML'] as $forbidden) {
    if (str_contains($browser, $forbidden)) {
        throw new RuntimeException('VR-028 favourites runtime leaked forbidden browser ownership: ' . $forbidden);
    }
}

echo "Wave 13 VR-028 /property/catalog Public Catalog passed.\n";
