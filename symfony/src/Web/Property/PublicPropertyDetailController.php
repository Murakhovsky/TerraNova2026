<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Command\RecordPublicPropertyViewCommand;
use App\Application\Property\Query\GetPublicPropertyDetailQuery;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Property\ViewModel\PublicPropertyDetailViewModel;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Shared\Domain\OrganizationId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PublicPropertyDetailController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private PagePresentationFactory $pages,
        private PublicPropertyDetailPresenter $presenter,
        private string $organizationId,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        $notice = $this->receiveLead($request);

        try {
            $data = $this->queries->ask(new GetPublicPropertyDetailQuery($slug));
            if (!is_array($data)) {
                return $this->render(
                    request: $request,
                    detail: null,
                    state: 'empty',
                    status: Response::HTTP_NOT_FOUND,
                );
            }

            $property = is_array($data['property'] ?? null) ? $data['property'] : [];
            $propertyId = (int) ($property['id'] ?? 0);
            if ($propertyId > 0) {
                $this->recordView($request, $propertyId);
            }

            $detail = $this->presenter->present(
                $data,
                $request->getSchemeAndHttpHost(),
                $notice,
            );

            return $this->render($request, $detail, 'normal', Response::HTTP_OK);
        } catch (Throwable $error) {
            error_log('property.public.detail_failed ' . $error->getMessage());

            return $this->render(
                request: $request,
                detail: null,
                state: 'error',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
                error: 'Не вдалося завантажити картку обʼєкта.',
            );
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
            error_log('property.public.detail_lead_failed ' . $error->getMessage());

            return 'Заявку не вдалося зберегти.';
        }
    }

    private function recordView(Request $request, int $propertyId): void
    {
        try {
            $this->commands->dispatch(new RecordPublicPropertyViewCommand(
                $propertyId,
                [
                    'source_page' => $request->getRequestUri(),
                    'referer' => (string) $request->headers->get('referer', ''),
                    'utm_source' => (string) $request->query->get('utm_source', ''),
                    'utm_medium' => (string) $request->query->get('utm_medium', ''),
                    'utm_campaign' => (string) $request->query->get('utm_campaign', ''),
                ],
            ));
        } catch (Throwable $error) {
            error_log('property.public.detail_view_failed ' . $error->getMessage());
        }
    }

    private function render(
        Request $request,
        ?PublicPropertyDetailViewModel $detail,
        string $state,
        int $status,
        ?string $error = null,
    ): Response {
        $patterns = ['PageHeader', 'ActionBar', 'StatGrid'];
        if ($detail === null) {
            $patterns[] = $state === 'error' ? 'ErrorState' : 'EmptyState';
        }

        return new Response(
            $this->twig->render('experience/public/property_detail.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicDetailMarketing,
                    $patterns,
                    $state,
                ),
                'detail' => $detail,
                'error' => $error,
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
