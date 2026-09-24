<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPropertyMapQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PropertyMapController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PropertyMapPresenter $presenter,
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            $data = $this->queries->ask(new GetPropertyMapQuery($request->query->all()));
            $map = $this->presenter->present(is_array($data) ? $data : []);

            return $this->render($request, [
                'page' => $this->pages->create(PageArchetype::MapSpatial, $this->patterns(), $map->state()),
                'map' => $map,
            ]);
        } catch (Throwable $error) {
            error_log('property.public.map_failed ' . $error->getMessage());
            $map = $this->presenter->present([], 'Карта об’єктів тимчасово недоступна.');

            return $this->render($request, [
                'page' => $this->pages->create(PageArchetype::MapSpatial, $this->patterns(), 'error'),
                'map' => $map,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'Toolbar',
            'ContextPanel',
            'ActionBar',
            'EntityList',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(Request $request, array $variables, int $status = Response::HTTP_OK): Response
    {
        $variables += [
            'metaTitle' => 'Карта об’єктів | Terra Nova CLUB',
            'metaDescription' => 'Карта об’єктів Terra Nova CLUB із фактичними географічними координатами.',
            'metaUrl' => $request->getSchemeAndHttpHost() . '/property/map',
        ];

        return new Response(
            $this->twig->render('experience/property/map.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=60',
            ],
        );
    }
}
