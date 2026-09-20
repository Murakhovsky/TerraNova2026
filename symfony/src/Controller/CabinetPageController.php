<?php
declare(strict_types=1);

namespace App\Controller;

use App\Web\Phtml\PhtmlRenderer;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CabinetPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        if ($tenant->isManager()) {
            return new RedirectResponse('/sales');
        }

        return new Response(
            $this->renderer->render($request, 'cabinet/canonical', [
                'title' => 'Кабінет',
                'metaTitle' => 'Кабінет | Terra Nova COS',
                'metaRobots' => 'noindex,nofollow',
                'interfaceSurface' => 'portal',
                'pageAssetEntries' => ['portal-cabinet'],
                'currentUser' => [
                    'id' => (int) $tenant->userId()->value(),
                    'role' => $tenant->role()->value(),
                ],
                'portalRole' => $tenant->role()->value(),
                'organizationId' => $tenant->organizationId()->value(),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    public function retiredSubmission(Request $request, string $id): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        return new Response(
            $this->renderer->render($request, 'cabinet/retired-submission', [
                'title' => 'Редагування заявки перенесено',
                'metaTitle' => 'Редагування заявки перенесено | Terra Nova COS',
                'metaRobots' => 'noindex,nofollow',
                'interfaceSurface' => 'portal',
                'pageAssetEntries' => ['portal-cabinet'],
                'currentUser' => [
                    'id' => (int) $tenant->userId()->value(),
                    'role' => $tenant->role()->value(),
                ],
                'portalRole' => $tenant->role()->value(),
                'submissionId' => (int) $id,
            ]),
            Response::HTTP_GONE,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
