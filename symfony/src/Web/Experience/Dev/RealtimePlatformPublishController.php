<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Realtime\RealtimeStreamPublisher;
use App\Web\Experience\Realtime\RealtimeTopicFactory;
use DateTimeImmutable;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class RealtimePlatformPublishController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private RealtimeTopicFactory $topics,
        private RealtimeStreamPublisher $publisher,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager()) {
            throw new AccessDeniedHttpException('Realtime Platform preview requires manager access.');
        }

        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $message = trim((string) $request->request->get('message', ''));
        if ($message === '') {
            $message = 'Realtime projection refreshed.';
        }
        $message = mb_substr($message, 0, 160);

        $this->publisher->publish(
            $this->topics->workspace(
                $tenant->organizationId()->value(),
                'realtime.preview',
            ),
            'experience/realtime/streams/demo.stream.html.twig',
            [
                'message' => $message,
                'publishedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
        );

        return new RedirectResponse('/dev/realtime?published=1', Response::HTTP_SEE_OTHER);
    }
}
