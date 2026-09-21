<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class DesignSystemCatalogController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        return new Response(
            $this->twig->render('experience/design_system_catalog.html.twig'),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
