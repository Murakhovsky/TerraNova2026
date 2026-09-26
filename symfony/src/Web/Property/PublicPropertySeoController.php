<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPublicPropertyCatalogQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertySeoController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private PagePresentationFactory $pages,
        private PublicPropertyCatalogPresenter $catalogs,
        private PublicPropertySeoPresenter $seo,
    ) {
    }

    public function type(Request $request, string $code): Response
    {
        return $this->page(
            request: $request,
            overrides: ['type' => $code],
            kicker: 'Категорія',
            title: 'Обʼєкти категорії',
            description: 'Добірка актуальних обʼєктів Terra Nova CLUB.',
            path: '/property/type/' . rawurlencode($code),
        );
    }

    public function city(Request $request, string $slug): Response
    {
        return $this->page(
            request: $request,
            overrides: ['location' => $slug],
            kicker: 'Місто',
            title: 'Обʼєкти у місті',
            description: 'Добірка актуальної нерухомості Terra Nova CLUB.',
            path: '/property/city/' . rawurlencode($slug),
        );
    }

    public function landing(Request $request, string $location, string $type): Response
    {
        return $this->page(
            request: $request,
            overrides: ['location' => $location, 'type' => $type],
            kicker: 'Локальна добірка',
            title: 'Нерухомість',
            description: 'Локальна добірка актуальної нерухомості Terra Nova CLUB.',
            path: '/nerukhomist/' . rawurlencode($location) . '/' . rawurlencode($type),
        );
    }

    /** @param array<string,mixed> $overrides */
    private function page(
        Request $request,
        array $overrides,
        string $kicker,
        string $title,
        string $description,
        string $path,
    ): Response {
        try {
            $query = array_replace($request->query->all(), $overrides);
            $data = $this->queries->ask(new GetPublicPropertyCatalogQuery($query));

            $catalog = $this->catalogs->present(
                is_array($data) ? $data : [],
                $request->getSchemeAndHttpHost(),
                pagePath: $path,
                pageName: $title,
            );
            $seo = $this->seo->present(
                $catalog,
                $kicker,
                $title,
                $description,
                $request->getSchemeAndHttpHost() . $path,
            );

            return $this->render($seo, Response::HTTP_OK);
        } catch (Throwable $error) {
            error_log('property.public.seo_failed ' . $error->getMessage());

            $catalog = $this->catalogs->present(
                [],
                $request->getSchemeAndHttpHost(),
                error: 'Дані добірки тимчасово недоступні.',
                pagePath: $path,
                pageName: $title,
            );
            $seo = $this->seo->present(
                $catalog,
                $kicker,
                $title,
                $description,
                $request->getSchemeAndHttpHost() . $path,
            );

            return $this->render($seo, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function render(
        \App\Web\Property\ViewModel\PublicPropertySeoViewModel $seo,
        int $status,
    ): Response {
        return new Response(
            $this->twig->render('experience/public/property_seo.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicCatalog,
                    ['PageHeader', 'EntityList', 'Pagination', 'EmptyState'],
                    $seo->state(),
                ),
                'seo' => $seo,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=120',
            ],
        );
    }
}
