<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use Kernel\Tenant\Model\TenantContext;

/** Only registered and currently resolved descriptors are displayable or executable. */
final readonly class OperationsRecoveryUI
{
    public function __construct(
        private OperationsRecoveryActionProvider $provider,
        private UIActionResolver $resolver,
    ) {}

    public function available(TenantContext $actor, WebExtensionContext $context, string $run): array
    {
        try {
            $entity = new EntityRef(OperationsRecoveryActionProvider::ENTITY_TYPE,$run);
            $resolved = [];
            foreach ($this->resolver->resolve($actor,$context,$entity,UIActionPlacement::WORKSPACE_SECONDARY) as $action) {
                if ($action->enabled && $action->confirmationContract() !== null && !$action->confirmationContract()->stepUp) {
                    $resolved[$action->id] = $action;
                }
            }
            $available = [];
            foreach ($this->provider->options($actor,$context,$entity) as $option) {
                $expected = $option['action'];
                $action = $resolved[$expected->id] ?? null;
                if ($action === null || $action->command !== $expected->command
                    || $action->resourceId !== $expected->resourceId || $action->permission !== $expected->permission) continue;
                $option['action'] = $action;
                $available[] = $option;
            }
            return $available;
        } catch (\Throwable $e) {
            error_log('cos.operations.recovery.resolve_unavailable '.$e::class);
            return [];
        }
    }
}
