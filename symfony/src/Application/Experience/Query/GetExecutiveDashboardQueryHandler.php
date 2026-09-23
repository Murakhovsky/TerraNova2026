<?php

declare(strict_types=1);

namespace App\Application\Experience\Query;

use Domains\Property\Application\Contract\PropertyWorkspaceReadModelInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Throwable;

final readonly class GetExecutiveDashboardQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceReadModelInterface $sales,
        private PropertyWorkspaceReadModelInterface $properties,
        private OperationsReadModelInterface $operations,
        private ActiveModuleResolver $modules,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetExecutiveDashboardQuery $query): array
    {
        $organizationId = $query->organizationId->value();
        $modules = $this->moduleSnapshot($organizationId);
        $moduleIndex = [];

        foreach ($modules['items'] as $module) {
            $moduleIndex[(string) ($module['id'] ?? '')] = $module;
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'modules' => $modules,
            'sales' => $this->section(
                (bool) ($moduleIndex['sales']['enabled'] ?? false),
                fn (): array => $this->sales->dashboard($organizationId),
            ),
            'property' => $this->section(
                (bool) ($moduleIndex['property']['enabled'] ?? false),
                fn (): array => $this->properties->overview($organizationId, 6),
            ),
            'cos' => $this->section(true, fn (): array => [
                'overview' => $this->operations->overview($organizationId, 12),
                'health' => $this->operations->health(),
            ]),
        ];
    }

    /** @return array{available:bool,items:list<array<string,mixed>>,error:?string} */
    private function moduleSnapshot(string $organizationId): array
    {
        try {
            return ['available' => true, 'items' => $this->modules->describe($organizationId), 'error' => null];
        } catch (Throwable) {
            return ['available' => false, 'items' => [], 'error' => 'Стан модулів тимчасово недоступний.'];
        }
    }

    /** @return array{enabled:bool,available:bool,data:array<string,mixed>,error:?string} */
    private function section(bool $enabled, callable $loader): array
    {
        if (!$enabled) {
            return ['enabled' => false, 'available' => false, 'data' => [], 'error' => null];
        }

        try {
            $data = $loader();

            return [
                'enabled' => true,
                'available' => true,
                'data' => is_array($data) ? $data : [],
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'enabled' => true,
                'available' => false,
                'data' => [],
                'error' => 'Дані цього модуля тимчасово недоступні.',
            ];
        }
    }
}
