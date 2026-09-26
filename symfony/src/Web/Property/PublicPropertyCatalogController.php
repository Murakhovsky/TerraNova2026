<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPublicPropertyCatalogQuery;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Shared\Domain\OrganizationId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertyCatalogController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private PagePresentationFactory $pages,
        private PublicPropertyCatalogPresenter $presenter,
        private string $organizationId,
    ) {
    }

    public function index(Request $request): Response
    {
        $notice = $this->receiveLead($request);

        try {
            $data = $this->queries->ask(new GetPublicPropertyCatalogQuery($request->query->all()));
            $catalog = $this->presenter->present(
                is_array($data) ? $data : [],
                $request->getSchemeAndHttpHost(),
                $notice,
            );

            return $this->render($request, $catalog, Response::HTTP_OK);
        } catch (Throwable $error) {
            error_log('property.public.catalog_read_failed ' . $error->getMessage());

            $catalog = $this->presenter->present(
                [],
                $request->getSchemeAndHttpHost(),
                $notice,
                'Дані обʼєктів тимчасово недоступні.',
            );

            return $this->render($request, $catalog, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function receiveLead(Request $request): ?string
    {
        if (!$request->isMethod('POST')) {
            return null;
        }

        try {
            $result = $this->commands->dispatch(new ReceivePublicLeadCommand(
                OrganizationId::fromString($this->organizationId),
                $request->request->all(),
                $request->getRequestUri(),
            ));

            if (!$result instanceof SalesMutationResult) {
                return 'Заявку не вдалося зберегти.';
            }

            return $result->ok
                ? 'Заявку прийнято. Менеджер звʼяжеться з вами.'
                : ($result->message ?? 'Заявку не вдалося зберегти.');
        } catch (Throwable $error) {
            error_log('property.public.catalog_lead_failed ' . $error->getMessage());

            return 'Заявку не вдалося зберегти.';
        }
    }

    private function render(
        Request $request,
        \App\Web\Property\ViewModel\PublicPropertyCatalogViewModel $catalog,
        int $status,
    ): Response {
        $page = $this->pages->create(
            PageArchetype::PublicCatalog,
            ['PageHeader', 'EntityList', 'FilterBar', 'Pagination', 'EmptyState'],
            $catalog->state(),
        );

        return new Response(
            $this->twig->render('experience/public/property_catalog.html.twig', [
                'page' => $page,
                'catalog' => $catalog,
            ]),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => $request->isMethod('GET')
                    ? 'public, max-age=60'
                    : 'no-store, private',
            ],
        );
    }
}
