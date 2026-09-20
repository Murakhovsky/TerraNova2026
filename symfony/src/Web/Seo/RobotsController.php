<?php
declare(strict_types=1);

namespace App\Web\Seo;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RobotsController
{
    public function __invoke(Request $request): Response
    {
        $body = implode("\n", [
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
            'Sitemap: ' . $request->getSchemeAndHttpHost() . '/sitemap.xml',
            '',
        ]);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
