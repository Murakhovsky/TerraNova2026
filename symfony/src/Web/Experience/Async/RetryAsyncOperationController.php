<?php

declare(strict_types=1);

namespace App\Web\Experience\Async;

use App\Application\Operations\Command\RetryAsyncOperationCommand;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class RetryAsyncOperationController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private CommandBusInterface $commands,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->allows(TenantPermissions::MANAGE)) {
            throw new AccessDeniedHttpException('Async operation retry requires manage permission.');
        }

        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->commands->dispatch(new RetryAsyncOperationCommand(
                organizationId: $tenant->organizationId()->value(),
                actorId: $tenant->userId()->value(),
                operationId: $id,
            ));
        } catch (DomainException $error) {
            return new Response($error->getMessage(), Response::HTTP_CONFLICT);
        }

        return new RedirectResponse(
            '/workspace/activity-center?tab=activity&operation=' . rawurlencode($id) . '&retried=1',
            Response::HTTP_SEE_OTHER,
        );
    }
}
