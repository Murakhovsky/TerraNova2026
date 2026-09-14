<?php
declare(strict_types=1);

namespace Kernel\Module;

final readonly class EffectiveModuleContext
{
    public function __construct(
        private ActiveModuleResolver $modules,
        private ModuleCapabilityRegistry $capabilities,
    ) {
    }

    /** @return array<string, mixed> */
    public function describe(string $organizationId): array
    {
        $descriptions = [];
        $activeModuleIds = [];
        $activeCapabilities = [];

        foreach ($this->modules->describe($organizationId) as $module) {
            $moduleId = (string) $module['id'];
            $declaredCapabilities = $this->capabilities->capabilitiesFor($moduleId);
            $isActive = (bool) ($module['active'] ?? false);
            $moduleActiveCapabilities = $isActive ? $declaredCapabilities : [];

            if ($isActive) {
                $activeModuleIds[] = $moduleId;
                array_push($activeCapabilities, ...$moduleActiveCapabilities);
            }

            $descriptions[] = $module + [
                'capabilities' => $declaredCapabilities,
                'active_capabilities' => $moduleActiveCapabilities,
            ];
        }

        return [
            'organization_id' => $organizationId,
            'kernel_version' => KernelVersion::VERSION,
            'modules' => $descriptions,
            'active_module_ids' => $activeModuleIds,
            'active_capabilities' => $activeCapabilities,
        ];
    }
}
