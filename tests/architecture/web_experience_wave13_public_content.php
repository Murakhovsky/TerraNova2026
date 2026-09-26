<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Wave 13 Public Content artifact is missing: ' . $path);
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

$controller = $read('symfony/src/Web/Content/PublicContentPageController.php');
foreach ([
    'GetPublicBlogQuery', 'GetPublicArticleQuery', 'GetPublicGuideQuery',
    'QueryBusInterface', 'PageArchetype::PublicCatalog',
    'PageArchetype::PublicDetailMarketing',
    "experience/public/blog.html.twig",
    "experience/public/article.html.twig",
    "experience/public/guide.html.twig",
] as $marker) {
    $contains($controller, $marker, 'Public Content controller contract is incomplete.');
}
foreach (['PhtmlRenderer', 'ContentServiceInterface $content'] as $forbidden) {
    $notContains($controller, $forbidden, 'Public Content controller must remain on Application Query boundary.');
}

foreach ([
    'symfony/templates/experience/public/blog.html.twig' => ['data-cos-public="blog"', '<twig:CosPageHeader'],
    'symfony/templates/experience/public/article.html.twig' => ['data-cos-public="article"', '<twig:CosPageHeader', 'application/ld+json'],
    'symfony/templates/experience/public/guide.html.twig' => ['data-cos-public="guide"', '<twig:CosPageHeader', 'application/ld+json'],
] as $path => $markers) {
    $source = $read($path);
    foreach ($markers as $marker) $contains($source, $marker, 'Public Content Twig contract is incomplete for ' . $path . '.');
    foreach (['tn-', 'style=', 'onclick='] as $forbidden) {
        $notContains($source, $forbidden, 'Public Content Twig restored legacy/local presentation for ' . $path . '.');
    }
}

foreach ([
    'app/Interfaces/Web/View/blog/index.phtml',
    'app/Interfaces/Web/View/blog/show.phtml',
    'app/Interfaces/Web/View/blog/landing.phtml',
] as $retired) {
    if (is_file($root . '/' . $retired)) throw new RuntimeException('Retired Content PHTML restored: ' . $retired);
}

$tracker = $read('docs/03-architecture/wave13-migration-tracker.md');
foreach (['VR-043','VR-044','VR-045'] as $id) {
    if (preg_match('/\\| '.preg_quote($id, '/').' \\|[^\\n]*\\| DONE \\|/', $tracker) !== 1) {
        throw new RuntimeException('Content tracker unit is not DONE: ' . $id);
    }
}
foreach (['## Фаза 10 — Content', '## Фаза 10 — завершення Content', 'Phase 11 — Public COS'] as $marker) {
    $contains($tracker, $marker, 'Content tracker closure is incomplete.');
}

echo "Wave 13 Phase 10 Public Content family complete.\n";
