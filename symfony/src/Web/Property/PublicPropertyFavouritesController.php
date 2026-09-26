<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPublicPropertyFavouritesQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertyFavouritesController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PublicPropertyFavouritesPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        $publicIds = [];
        if ($request->hasSession()) {
            $publicIds = array_values(array_filter(
                $request->getSession()->get('cos_public_property_favourites', []),
                static fn (mixed $id): bool => is_string($id) && $id !== '',
            ));
        }

        try {
            $data = $this->queries->ask(new GetPublicPropertyFavouritesQuery($publicIds));
            $favourites = $this->presenter->present(is_array($data) ? $data : []);

            return $this->render($favourites, Response::HTTP_OK);
        } catch (Throwable $error) {
            error_log('property.public.favourites_failed ' . $error->getMessage());
            $favourites = $this->presenter->present(
                [],
                'Стан вибраного тимчасово недоступний.',
            );

            return $this->render($favourites, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function render(
        \App\Web\Property\ViewModel\PublicPropertyFavouritesViewModel $favourites,
        int $status,
    ): Response {
        return new Response(
            $this->twig->render('experience/public/property_favourites.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicCatalog,
                    ['PageHeader', 'EntityList', 'EmptyState'],
                    $favourites->state(),
                ),
                'favourites' => $favourites,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
