<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class VisualStressController
{
    public function __construct(
        private Environment $twig,
        private DataGridCatalogDemo $dataGrid,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return new Response(
            $this->twig->render('experience/visual_stress.html.twig', [
                'dataGridDemo' => $this->dataGrid->build($request),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
