<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleContributions
{
    /**
     * @param list<string> $jobHandlerServices
     * @param list<string> $apiRouteContributorServices
     * @param list<string> $capabilities
     * @param list<string> $migrationFiles
     * @param list<string> $configurationProvisionerServices
     */
    public function __construct(
        public ?string $runtimeModuleService = null,
        public array $jobHandlerServices = [],
        public array $apiRouteContributorServices = [],
        public array $capabilities = [],
        public array $migrationFiles = [],
        public array $configurationProvisionerServices = [],
    ) {
        if ($this->runtimeModuleService !== null) {
            self::assertServiceId($this->runtimeModuleService);
        }

        foreach ([
            'job handler' => $this->jobHandlerServices,
            'API route contributor' => $this->apiRouteContributorServices,
            'configuration provisioner' => $this->configurationProvisionerServices,
        ] as $kind => $serviceIds) {
            self::assertUniqueStrings($serviceIds, $kind);
            foreach ($serviceIds as $serviceId) {
                self::assertServiceId($serviceId);
            }
        }

        self::assertUniqueStrings($this->capabilities, 'capability');
        foreach ($this->capabilities as $capability) {
            if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $capability)) {
                throw new InvalidArgumentException(sprintf('Invalid module capability: %s.', $capability));
            }
        }

        self::assertUniqueStrings($this->migrationFiles, 'migration file');
        foreach ($this->migrationFiles as $migrationFile) {
            if (
                $migrationFile === ''
                || str_starts_with($migrationFile, '/')
                || str_contains($migrationFile, '..')
                || !str_ends_with($migrationFile, '.sql')
            ) {
                throw new InvalidArgumentException(sprintf('Invalid module migration path: %s.', $migrationFile));
            }
        }
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $runtime = $definition['runtime_module_service'] ?? null;

        return new self(
            is_string($runtime) && $runtime !== '' ? $runtime : null,
            self::stringList($definition['job_handler_services'] ?? []),
            self::stringList($definition['api_route_contributor_services'] ?? []),
            self::stringList($definition['capabilities'] ?? []),
            self::stringList($definition['migration_files'] ?? []),
            self::stringList($definition['configuration_provisioner_services'] ?? []),
        );
    }

    /** @return list<string> */
    public function allServiceIds(): array
    {
        return array_values(array_filter([
            $this->runtimeModuleService,
            ...$this->jobHandlerServices,
            ...$this->apiRouteContributorServices,
            ...$this->configurationProvisionerServices,
        ], static fn (?string $value): bool => $value !== null));
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /** @param list<string> $values */
    private static function assertUniqueStrings(array $values, string $kind): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException(sprintf('Module contains duplicate %s contributions.', $kind));
        }
    }

    private static function assertServiceId(string $serviceId): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $serviceId)) {
            throw new InvalidArgumentException(sprintf('Invalid DI service id in module contribution: %s.', $serviceId));
        }
    }
}
