<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleControlService;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleLifecycleManager;
use Kernel\Module\ModuleManifest;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$states = new class implements ModuleStateRepositoryInterface {
    public array $values = [];
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return $this->values[$organizationId . ':' . $moduleId] ?? null; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void { $this->values[$organizationId . ':' . $moduleId] = $enabled; }
};

$installations = new class implements ModuleLifecycleRepositoryInterface {
    public array $values = [];
    public function find(string $organizationId, string $moduleId): ?ModuleInstallation { return $this->values[$organizationId . ':' . $moduleId] ?? null; }
    public function forOrganization(string $organizationId): array { return array_values(array_filter($this->values, static fn (ModuleInstallation $installation): bool => $installation->organizationId === $organizationId)); }
    public function record(string $organizationId, ModuleManifest $manifest, string $status): void { $this->values[$organizationId . ':' . $manifest->id] = new ModuleInstallation($organizationId, $manifest->id, $status, $manifest->version, $manifest->schemaVersion); }
};

$audit = new class implements AuditRepositoryInterface {
    public array $entries = [];
    public function append(AuditEntry $entry): void { $this->entries[] = $entry; }
};

$events = new class implements EventStoreInterface {
    public array $events = [];
    public function append(DomainEvent $event): void { $this->events[] = $event; }
    public function find(string $eventId): ?DomainEvent { foreach ($this->events as $event) if ($event->id === $eventId) return $event; return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return array_slice(array_values(array_filter($this->events, static fn (DomainEvent $event): bool => $event->organizationId === $organizationId && $event->aggregateType === $aggregateType && $event->aggregateId === $aggregateId)), 0, $limit); }
};

$transactions = new class implements TransactionManagerInterface {
    public bool $active = false;
    public function transactional(callable $operation): mixed { if ($this->active) return $operation(); $this->active = true; try { return $operation(); } finally { $this->active = false; } }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
};

$catalog = new ModuleCatalog([new ModuleManifest('property', 'Property', '1.0.0', enabledByDefault: false)]);
$lifecycle = new ModuleLifecycleManager($catalog, $states, $installations);
$resolver = new ActiveModuleResolver($catalog, $states, $installations);
$control = new ModuleControlService($lifecycle, $resolver, $audit, new EventBus($events, $transactions), $transactions);

$state = $control->install('org-a', 'property', '42', 'corr-install', 'Tenant enabled Property.');
if (($state['active'] ?? false) !== true || ($state['installed'] ?? false) !== true) throw new RuntimeException('Module control install did not return active installed state.');
if (count($audit->entries) !== 1 || count($events->events) !== 1) throw new RuntimeException('Module control install did not emit exactly one audit and one event.');
if (($audit->entries[0]->data['action'] ?? null) !== 'module.install') throw new RuntimeException('Module control audit action is incorrect.');
if ($events->events[0]->type !== ModuleControlService::EVENT_LIFECYCLE_CHANGED || ($events->events[0]->payload['operation'] ?? null) !== 'install' || $events->events[0]->metadata->actorId !== '42' || $events->events[0]->metadata->correlationId !== 'corr-install') throw new RuntimeException('Module lifecycle event metadata is incorrect.');

$state = $control->disable('org-a', 'property', '42', 'corr-disable');
if (($state['active'] ?? true) !== false || ($state['configured_enabled'] ?? true) !== false) throw new RuntimeException('Module control disable did not return inactive state.');
if (count($audit->entries) !== 2 || count($events->events) !== 2) throw new RuntimeException('Module control disable did not emit audit and event.');
if (($audit->entries[1]->data['action'] ?? null) !== 'module.disable') throw new RuntimeException('Module control disable audit action is incorrect.');

echo "Module control plane invariants passed.\n";
