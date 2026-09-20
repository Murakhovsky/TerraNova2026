<?php
declare(strict_types=1);

namespace App\Web\Seo;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Domains\Property\Application\Contract\PublicPropertyReadRepositoryInterface;
use Domains\Content\Application\Service\PublicPageCatalog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SitemapController
{
    public function __construct(
        private PublicPropertyReadRepositoryInterface $properties,
        private ContentServiceInterface $content,
        private PublicPageCatalog $pages,
        private string $organizationId,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0'],
            ['loc' => $base . '/property/catalog', 'priority' => '0.9'],
            ['loc' => $base . '/property/submit', 'priority' => '0.6'],
            ['loc' => $base . '/blog', 'priority' => '0.7'],
        ];

        foreach ($this->pages->pages() as $page) {
            $urls[] = ['loc' => $base . '/' . ltrim((string) $page['path'], '/'), 'priority' => '0.6'];
        }
        foreach ($this->properties->sitemapTypes($this->organizationId) as $type) {
            $urls[] = ['loc' => $base . '/property/type/' . rawurlencode((string) $type['code']), 'priority' => '0.7'];
        }
        foreach ($this->properties->sitemapLocations($this->organizationId) as $location) {
            $urls[] = ['loc' => $base . '/property/city/' . rawurlencode((string) $location['slug']), 'priority' => '0.7'];
        }
        foreach ($this->properties->sitemapLandingPairs($this->organizationId) as $pair) {
            $urls[] = [
                'loc' => $base . '/nerukhomist/'
                    . rawurlencode((string) $pair['location_slug']) . '/'
                    . rawurlencode((string) $pair['type_code']),
                'priority' => '0.8',
            ];
        }
        foreach ($this->properties->sitemapProperties($this->organizationId) as $property) {
            $urls[] = [
                'loc' => $base . '/property/show/' . rawurlencode((string) $property['slug']),
                'lastmod' => substr((string) $property['updated_at'], 0, 10),
                'priority' => '0.9',
            ];
        }
        foreach ($this->content->sitemapItems() as $item) {
            $prefix = $item['content_type'] === 'blog_post' ? 'blog/' : 'guide/';
            $urls[] = [
                'loc' => $base . '/' . $prefix . rawurlencode((string) $item['slug']),
                'lastmod' => substr((string) $item['updated_at'], 0, 10),
                'priority' => $item['content_type'] === 'seo_landing' ? '0.8' : '0.7',
            ];
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (!empty($url['lastmod'])) {
                $xml[] = '    <lastmod>' . htmlspecialchars((string) $url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</lastmod>';
            }
            $xml[] = '    <priority>' . $url['priority'] . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        return new Response(implode("\n", $xml), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
