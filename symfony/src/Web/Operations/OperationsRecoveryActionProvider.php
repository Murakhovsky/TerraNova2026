<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Experience\Extension\Contract\ActionProviderInterface;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Operations\Read\RecoveryCandidateRead;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Integrator contribution: web.actions / operationsRecoveryActionProvider. */
final readonly class OperationsRecoveryActionProvider implements ActionProviderInterface
{
    public const ENTITY_TYPE = 'operations.workflow';

    public function __construct(
        private TenantContextProviderInterface $tenants,
        private OperationsSnapshot $snapshots,
        private OperationsRecoveryRead $recovery,
        private RecoveryCandidateRead $candidates,
    ) {}

    public function serviceId(): string { return 'operationsRecoveryActionProvider'; }

    public function actions(WebExtensionContext $context, ?EntityRef $entity = null): array
    {
        if ($entity === null || $entity->type !== self::ENTITY_TYPE || $context->activeSection !== 'operations') return [];
        $actor = $this->tenants->current();
        if ($actor === null) return [];
        return array_column($this->options($actor,$context,$entity),'action');
    }

    public function options(TenantContext $actor, WebExtensionContext $context, ?EntityRef $entity): array
    {
        if ($entity === null || $entity->type !== self::ENTITY_TYPE || !preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$entity->id)
            || $context->activeSection !== 'operations'
            || $actor->organizationId()->value() !== $context->organizationId
            || $actor->role()->value() !== $context->role
            || !$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS)
            || !$actor->allows(TenantPermissions::MANAGE)) return [];
        try {
            $run = $this->snapshots->one($actor,'workflow',$entity->id);
            if ($run === null || !in_array($run['native'],['running','waiting','ambiguous'],true)
                || (int)$run['revision']<1) return [];
            $candidates = $this->candidates->forRun($context->organizationId,$entity->id,(int)$run['revision']);
            if ($candidates === []) return [];
            $this->recovery->inspect($actor,$run); // independently check canonical diagnosis / revision
            $requests=$this->candidates->activeRequests($context->organizationId,$entity->id,(int)$run['revision']);
            return RecoveryActionPlan::build($entity->id,(int)$run['revision'],$actor->userId()->value(),$candidates,$requests);
        } catch (\Throwable $e) {
            error_log('cos.operations.recovery.options_unavailable '.$e::class);
            return []; // fail closed on missing, stale or corrupt canonical evidence
        }
    }
}
