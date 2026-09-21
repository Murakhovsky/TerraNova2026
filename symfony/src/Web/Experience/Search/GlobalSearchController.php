<?php

declare(strict_types=1);

namespace App\Web\Experience\Search;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class GlobalSearchController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private GlobalSearchService $search,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            throw new AccessDeniedHttpException('Authenticated tenant context is required.');
        }

        $query = trim((string) $request->query->get('q', ''));
        if (strlen($query) > 120) {
            $query = substr($query, 0, 120);
        }

        $limit = max(1, min(30, (int) $request->query->get('limit', 20)));

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
        );

        $results = $this->search->search($context, $query, $limit);

        return new Response(
            $this->twig->render('experience/search/results_frame.html.twig', [
                'results' => $results,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}
