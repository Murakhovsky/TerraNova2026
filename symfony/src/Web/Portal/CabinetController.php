<?php
declare(strict_types=1);

namespace App\Web\Portal;

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class CabinetController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private PagePresentationFactory $pages,
        private CabinetPresenter $presenter,
    ) {}

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if ($tenant->isManager()) {
            return new RedirectResponse('/sales');
        }

        return new Response(
            $this->twig->render('experience/portal/cabinet.html.twig', [
                'page' => $this->pages->create(PageArchetype::Portal, ['PageHeader']),
                'cabinet' => $this->presenter->present($tenant),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    public function retiredSubmission(string $id): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }

        $submissionId = max(1, (int) $id);

        return new Response(
            $this->twig->render('experience/portal/submission_retired.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::Portal,
                    ['PageHeader'],
                    'error',
                ),
                'submission' => $this->presenter->retiredSubmission($submissionId),
            ]),
            Response::HTTP_GONE,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
