<?php
declare(strict_types=1);

namespace App\Web\PublicSite;

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class HomeController
{
    public function __construct(
        private Environment $twig,
        private PagePresentationFactory $pages,
    ) {}

    public function __invoke(): Response
    {
        return new Response(
            $this->twig->render('experience/public/home.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicDetailMarketing,
                    ['PageHeader'],
                ),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=60',
            ],
        );
    }
}
