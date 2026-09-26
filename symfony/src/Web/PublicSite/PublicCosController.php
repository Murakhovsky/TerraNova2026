<?php
declare(strict_types=1);

namespace App\Web\PublicSite;

use App\Application\Content\Query\GetPublicCosLandingQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicCosController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PublicCosPresenter $presenter,
    ) {}

    public function landing(Request $request, string $lang = 'en'): Response
    {
        try {
            $model = $this->presenter->landing(
                $this->queries->ask(new GetPublicCosLandingQuery($lang))
            );
        } catch (Throwable $error) {
            error_log('public.cos.landing_failed ' . $error->getMessage());

            return new Response('COS is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response(
            $this->twig->render('experience/public/cos_landing.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicDetailMarketing,
                    ['PageHeader', 'ActionBar'],
                ),
                'cos' => $model,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
                'Content-Language' => $model->lang,
            ],
        );
    }
}
