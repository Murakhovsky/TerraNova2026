<?php
declare(strict_types=1);

namespace Kernel\Module;

use DateTimeImmutable;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ModuleControlService
{
    public const EVENT_LIFECYCLE_CHANGED = 'platform.module.lifecycle_changed';

    public function __construct(
        private ModuleLifecycleManager $lifecycle,
        private ActiveModuleResolver $modules,
        private AuditRepositoryInterface $audit,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private ?ModuleTenantProvisioner $provisioner = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function install(string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason = null): array
    {
        return $this->change('install', $organizationId, $moduleId, $actorId, $correlationId, $reason, function () use ($organizationId, $moduleId, $actorId): void {
            $this->provisioner?->provision($organizationId, $moduleId, $actorId);
            $this->lifecycle->install($organizationId, $moduleId, true);
        });
    }

    /** @return array<string, mixed> */
    public function upgrade(string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason = null): array
    {
        return $this->change('upgrade', $organizationId, $moduleId, $actorId, $correlationId, $reason, function () use ($organizationId, $moduleId, $actorId): void {
            $this->provisioner?->provision($organizationId, $moduleId, $actorId);
            $this->lifecycle->upgrade($organizationId, $moduleId);
        });
    }

    /** @return array<string, mixed> */
    public function enable(string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason = null): array
    {
        return $this->change('enable', $organizationId, $moduleId, $actorId, $correlationId, $reason, function () use ($organizationId, $moduleId, $actorId): void {
            if (!$this->modules->isInstalled($organizationId, $moduleId)) {
                $this->provisioner?->provision($organizationId, $moduleId, $actorId);
            }
            $this->lifecycle->enable($organizationId, $moduleId);
        });
    }

    /** @return array<string, mixed> */
    public function disable(string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason = null): array
    {
        return $this->change('disable', $organizationId, $moduleId, $actorId, $correlationId, $reason, fn () => $this->lifecycle->disable($organizationId, $moduleId));
    }

    /** @return array<string, mixed> */
    public function uninstall(string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason = null): array
    {
        return $this->change('uninstall', $organizationId, $moduleId, $actorId, $correlationId, $reason, fn () => $this->lifecycle->uninstall($organizationId, $moduleId));
    }

    /** @param callable(): void $mutation @return array<string, mixed> */
    private function change(string $operation, string $organizationId, string $moduleId, string $actorId, string $correlationId, ?string $reason, callable $mutation): array
    {
        return $this->transactions->transactional(function () use ($operation, $organizationId, $moduleId, $actorId, $correlationId, $reason, $mutation): array {
            $before = $this->modules->describeModule($organizationId, $moduleId);
            $mutation();
            $after = $this->modules->describeModule($organizationId, $moduleId);
            $now = new DateTimeImmutable();

            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)), $organizationId, 'MODULE_LIFECYCLE', 'USER', $actorId, 'module', $moduleId, $reason,
                [
                    'action' => 'module.' . $operation,
                    'input_references' => ['module_id' => $moduleId],
                    'changes' => ['before' => $before, 'after' => $after],
                    'result' => ['status' => 'SUCCESS'],
                    'metadata' => ['kernel_version' => KernelVersion::VERSION],
                ],
                $correlationId, $now,
            ));

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)), $organizationId, self::EVENT_LIFECYCLE_CHANGED, 'module', $moduleId,
                ['operation' => $operation, 'before' => $before, 'after' => $after],
                new EventMetadata($correlationId, null, 'USER', $actorId), $now,
            ));

            return $after;
        });
    }
}
