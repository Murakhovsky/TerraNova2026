<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

/**
 * Canonical metadata projection of module manifests. Does not dispatch execution.
 * Legacy descriptive contributions remain visible but cannot be auto-executed.
 */
final class CanonicalCapabilityCatalog
{
    /** @var array<string, CapabilityContract> */
    private array $contracts = [];

    /** @var array<string, string> */
    private array $owners = [];

    public function __construct(ModuleCatalog $catalog)
    {
        $registry = new ModuleCapabilityRegistry($catalog);
        $this->owners = $registry->all();

        foreach ($catalog->definitions() as $definition) {
            foreach ($definition->contributions->capabilityContracts as $contract) {
                if ($contract->ownerDomain !== $definition->manifest->id) {
                    throw new InvalidArgumentException(sprintf('Capability %s owner %s differs from manifest %s.', $contract->id, $contract->ownerDomain, $definition->manifest->id));
                }
                if (($this->owners[$contract->id] ?? null) !== $definition->manifest->id) {
                    throw new InvalidArgumentException('Executable capability must have a matching identity in module.php: ' . $contract->id);
                }
                if (isset($this->contracts[$contract->id])) {
                    throw new InvalidArgumentException('Duplicate executable capability: ' . $contract->id);
                }
                $this->contracts[$contract->id] = $contract;
            }
        }
        ksort($this->contracts);
        ksort($this->owners);
    }

    public function describe(string $id): ?CapabilityContract
    {
        return $this->contracts[$id] ?? null;
    }

    public function ownerOf(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }

    /** @return array<string,CapabilityContract> */
    public function executables(): array
    {
        return $this->contracts;
    }

    /** @return array<string,string> */
    public function declared(): array
    {
        return $this->owners;
    }

    /**
     * Status is about declaration/binding reconciliation, NOT runtime authorization.
     * $registeredBindings is an evidence map from the existing DI/handler/tool runtime:
     * binding id => canonical capability id.
     *
     * @param array<string,string> $registeredBindings
     * @return array<string,array{owner:string,status:string,binding:?string}>
     */
    public function driftReport(array $registeredBindings = []): array
    {
        $report = [];
        foreach ($this->owners as $id => $owner) {
            $contract = $this->contracts[$id] ?? null;
            $status = $contract === null ? 'DECLARATIVE_ONLY' : 'INVALID';
            if ($contract !== null) {
                $status = $contract->lifecycle === 'deprecated' || $contract->lifecycle === 'retired'
                    ? 'DEPRECATED'
                    : (($registeredBindings[$contract->executionBinding] ?? null) === $id ? 'CONSISTENT' : 'INVALID');
            }
            $report[$id] = ['owner' => $owner, 'status' => $status, 'binding' => $contract?->executionBinding];
        }
        foreach ($registeredBindings as $binding => $id) {
            if (!isset($this->owners[$id])) {
                $report[$id] = ['owner' => '', 'status' => 'EXECUTABLE_ONLY', 'binding' => $binding];
            }
        }
        ksort($report);
        return $report;
    }
}
