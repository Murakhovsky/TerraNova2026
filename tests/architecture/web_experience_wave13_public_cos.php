<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Wave 13 Public COS artifact is missing: ' . $path);
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

$catalog = $read('symfony/src/Application/Content/Service/PublicCosCatalog.php');
foreach ([
    "private const LANGUAGES = ['en', 'de', 'fr', 'pl', 'uk']",
    "'executive-control' =>",
    "'data-fabric' =>",
    'public function landing(string $lang): array',
    'public function domain(string $lang, string $slug): ?array',
] as $marker) {
    $contains($catalog, $marker, 'Public COS catalog contract is incomplete.');
}

$controller = $read('symfony/src/Web/PublicSite/PublicCosController.php');
foreach ([
    'GetPublicCosLandingQuery',
    'GetPublicCosDomainQuery',
    'QueryBusInterface',
    'PageArchetype::PublicDetailMarketing',
    "experience/public/cos_landing.html.twig",
    "experience/public/cos_domain.html.twig",
] as $marker) {
    $contains($controller, $marker, 'Public COS controller contract is incomplete.');
}
foreach (['PhtmlRenderer', 'ViteAssetManifest'] as $forbidden) {
    $notContains($controller, $forbidden, 'Public COS controller must not depend on legacy rendering.');
}

$baseTemplate = $read('symfony/templates/base.html.twig');
$contains($baseTemplate, '{% block html_lang %}uk{% endblock %}', 'Base document language must be overridable.');

foreach ([
    'symfony/templates/experience/public/cos_landing.html.twig' => ['data-cos-public="cos-landing"', '<twig:CosPageHeader', '<twig:CosActionBar', '{% block html_lang %}{{ cos.lang }}{% endblock %}'],
    'symfony/templates/experience/public/cos_domain.html.twig' => ['data-cos-public="cos-domain"', '<twig:CosPageHeader', '<twig:CosActionBar', '{% block html_lang %}{{ cos.lang }}{% endblock %}'],
] as $path => $markers) {
    $source = $read($path);
    foreach ($markers as $marker) $contains($source, $marker, 'Public COS Twig contract is incomplete: ' . $path);
    foreach (['tn-', 'style=', 'onclick=', '<script'] as $forbidden) {
        $notContains($source, $forbidden, 'Public COS Twig restored legacy/local presentation: ' . $path);
    }
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /cos',
    'path: /cos/{lang}',
    'path: /cos/{lang}/domains/{slug}',
    'PublicCosController::landing',
    'PublicCosController::domain',
    "lang: 'en|de|fr|pl|uk'",
] as $marker) {
    $contains($routes, $marker, 'Public COS route ownership is incomplete.');
}

foreach ([
    'app/Interfaces/Web/View/company_os/index.phtml',
    'app/Interfaces/Web/View/company_os/domain.phtml',
    'app/Interfaces/Web/View/company_os/not_found.phtml',
    'frontend/entrypoints/cos-site.js',
    'frontend/styles/cos-site.css',
] as $retired) {
    if (is_file($root . '/' . $retired)) throw new RuntimeException('Retired Public COS artifact restored: ' . $retired);
}

$vite = $read('vite.config.js');
$notContains($vite, "'cos-site':", 'Retired Public COS Vite entrypoint remains configured.');

$tracker = $read('docs/03-architecture/wave13-migration-tracker.md');
foreach (['VR-046','VR-047'] as $id) {
    if (preg_match('/\\| '.preg_quote($id, '/').' \\|[^\\n]*\\| DONE \\|/', $tracker) !== 1) {
        throw new RuntimeException('Public COS tracker unit is not DONE: ' . $id);
    }
}
foreach (['## Фаза 11 — Public COS', '## Фаза 11 — завершення Public COS', 'фінальний Wave 13 audit'] as $marker) {
    $contains($tracker, $marker, 'Public COS tracker closure is incomplete.');
}

echo "Wave 13 Phase 11 Public COS family complete.\n";
