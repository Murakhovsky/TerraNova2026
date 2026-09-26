<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Command\RecordPublicPropertyViewCommand;
use App\Application\Property\Query\GetPublicPropertyPresentationQuery;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\SalesMutationResult;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Property\ViewModel\PublicPropertyDetailViewModel;
use App\Web\Property\ViewModel\PublicPropertyGroupPresentationViewModel;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PropertyPageController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private PagePresentationFactory $pages,
        private PublicPropertyDetailPresenter $detailPresenter,
        private PublicPropertyGroupPresentationPresenter $groupPresenter,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private string $organizationId,
    ) {
    }

    public function presentation(Request $request, string $slug): Response
    {
        $notice = $this->receiveLead($request);

        try {
            $result = $this->queries->ask(new GetPublicPropertyPresentationQuery($slug));
            if (!is_array($result)) {
                return $this->render(
                    request: $request,
                    detail: null,
                    group: null,
                    state: 'empty',
                    status: Response::HTTP_NOT_FOUND,
                    error: 'Презентацію не знайдено.',
                );
            }

            if (($result['kind'] ?? null) === 'property') {
                $data = is_array($result['data'] ?? null) ? $result['data'] : [];
                $property = is_array($data['property'] ?? null) ? $data['property'] : [];
                $propertyId = (int) ($property['id'] ?? 0);
                if ($propertyId > 0) {
                    $this->recordView($request, $propertyId);
                }

                $detail = $this->detailPresenter->present(
                    $data,
                    $request->getSchemeAndHttpHost(),
                    $notice,
                );

                return $this->render($request, $detail, null, 'normal', Response::HTTP_OK);
            }

            $groupData = is_array($result['group'] ?? null) ? $result['group'] : [];
            $properties = is_array($result['properties'] ?? null)
                ? array_values(array_filter($result['properties'], 'is_array'))
                : [];
            $group = $this->groupPresenter->present(
                $groupData,
                $properties,
                $request->getSchemeAndHttpHost(),
            );

            return $this->render($request, null, $group, 'normal', Response::HTTP_OK, notice: $notice);
        } catch (Throwable $error) {
            error_log('property.public.presentation_failed ' . $error->getMessage());

            return $this->render(
                request: $request,
                detail: null,
                group: null,
                state: 'error',
                status: Response::HTTP_SERVICE_UNAVAILABLE,
                error: 'Презентація тимчасово недоступна.',
            );
        }
    }

    public function pdf(string $slug): Response
    {
        return new RedirectResponse(
            '/property/presentation/' . rawurlencode($slug) . '?print=1',
            Response::HTTP_FOUND,
        );
    }

    public function presentationShare(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }
        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $slug = trim((string) $request->request->get('slug', ''));
        if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            return new Response('Invalid property slug.', Response::HTTP_BAD_REQUEST);
        }

        return new RedirectResponse('/property/presentation/' . rawurlencode($slug));
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
            error_log('property.public.presentation_lead_failed ' . $error->getMessage());

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
            error_log('property.public.presentation_view_failed ' . $error->getMessage());
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    private function render(
        Request $request,
        ?PublicPropertyDetailViewModel $detail,
        ?PublicPropertyGroupPresentationViewModel $group,
        string $state,
        int $status,
        ?string $error = null,
        ?string $notice = null,
    ): Response {
        return new Response(
            $this->twig->render('experience/public/property_presentation.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::PublicDetailMarketing,
                    ['PageHeader', 'ActionBar', 'StatGrid', 'EmptyState', 'ErrorState'],
                    $state,
                ),
                'detail' => $detail,
                'group' => $group,
                'error' => $error,
                'notice' => $notice,
                'printMode' => $request->query->getBoolean('print'),
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
