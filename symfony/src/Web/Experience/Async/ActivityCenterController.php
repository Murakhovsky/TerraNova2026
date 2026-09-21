<?php

declare(strict_types=1);

namespace App\Web\Experience\Async;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class ActivityCenterController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private WebExtensionCatalog $extensions,
        private AsyncOperationReadModelInterface $operations,
        private RealtimeTopicFactory $topics,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            throw new AccessDeniedHttpException('Activity Center requires authentication.');
        }

        $tab = strtolower(trim((string) $request->query->get('tab', 'activity')));
        if (!in_array($tab, ['activity', 'notifications'], true)) {
            $tab = 'activity';
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: trim((string) $request->query->get('section', '')),
            activeItem: trim((string) $request->query->get('item', '')),
        );
        $catalog = $this->extensions->forContext($context);

        $activity = $catalog->activity(50);
        $notifications = $catalog->notifications(30);

        $selected = null;
        $operationId = trim((string) $request->query->get('operation', ''));
        if ($operationId !== '') {
            $selected = $this->operations->find($tenant->organizationId()->value(), $operationId);
        }

        return new Response(
            $this->twig->render('experience/async/activity_center_frame.html.twig', [
                'tab' => $tab,
                'activity' => $activity,
                'notifications' => $notifications,
                'selected' => $selected,
                'topic' => $this->topics->organization($tenant->organizationId()->value()),
                'retried' => $request->query->getBoolean('retried'),
                'csrfToken' => $this->csrf->token($request),
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
