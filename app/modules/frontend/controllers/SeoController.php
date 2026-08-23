<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

class SeoController extends ControllerBase
{
    public function sitemapAction(): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();
        $urls = [
            ['loc' => $this->seoAbsoluteUrl(''), 'priority' => '1.0'],
            ['loc' => $this->seoAbsoluteUrl('property/catalog'), 'priority' => '0.9'],
            ['loc' => $this->seoAbsoluteUrl('property/submit'), 'priority' => '0.6'],
        ];

        foreach ($this->publicPageService()->pages() as $page) {
            $urls[] = ['loc' => $this->seoAbsoluteUrl((string) $page['path']), 'priority' => '0.6'];
        }
        foreach ($this->catalogService()->propertyTypes() as $type) {
            $urls[] = ['loc' => $this->seoAbsoluteUrl('property/type/' . $type['code']), 'priority' => '0.7'];
        }
        foreach ($this->catalogService()->locations() as $location) {
            $urls[] = ['loc' => $this->seoAbsoluteUrl('property/city/' . $location['slug']), 'priority' => '0.7'];
        }
        foreach ($this->catalogService()->seoLandingPairs() as $pair) {
            $urls[] = ['loc' => $this->seoAbsoluteUrl('nerukhomist/' . $pair['location_slug'] . '/' . $pair['type_code']), 'priority' => '0.8'];
        }
        foreach ($this->catalogService()->sitemapProperties() as $property) {
            $urls[] = [
                'loc' => $this->seoAbsoluteUrl('property/show/' . $property['slug']),
                'lastmod' => substr((string) $property['updated_at'], 0, 10),
                'priority' => '0.9',
            ];
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1, 'UTF-8') . '</loc>';
            if (!empty($url['lastmod'])) {
                $xml[] = '    <lastmod>' . $url['lastmod'] . '</lastmod>';
            }
            $xml[] = '    <priority>' . $url['priority'] . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        $this->response->setContentType('application/xml', 'UTF-8');
        $this->response->setContent(implode("\n", $xml));

        return $this->response;
    }

    public function robotsAction(): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent(implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /auth',
            'Disallow: /cabinet',
            'Disallow: /client-case',
            'Disallow: /property/manage',
            'Disallow: /property/listing',
            'Disallow: /property/edit',
            'Disallow: /property/submissions',
            'Sitemap: ' . $this->seoAbsoluteUrl('sitemap.xml'),
            '',
        ]));

        return $this->response;
    }

    private function seoAbsoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}
