<?php
declare(strict_types=1);

namespace Interfaces\Web\Service;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Throwable;

final readonly class CompanyHomeService
{
    public function __construct(
        private SalesWorkspaceReadModelInterface $sales,
        private OperationsReadModelInterface $operations,
        private ActiveModuleResolver $modules,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(string $organizationId): array
    {
        $modules = $this->moduleSnapshot($organizationId);
        $moduleIndex = [];
        foreach ($modules['items'] as $module) {
            $moduleIndex[(string) ($module['id'] ?? '')] = $module;
        }

        $salesEnabled = (bool) ($moduleIndex['sales']['enabled'] ?? false);
        $propertyEnabled = (bool) ($moduleIndex['property']['enabled'] ?? false);

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'modules' => $modules,
            'sales' => $this->section($salesEnabled, fn (): array => $this->sales->dashboard($organizationId)),
            'property' => $propertyEnabled
                ? [
                    'enabled' => true,
                    'available' => false,
                    'data' => [],
                    'error' => 'Контракт читання Property з прив’язкою до організації ще не готовий; загальні дані каталогу навмисно не використовуються.',
                ]
                : ['enabled' => false, 'available' => false, 'data' => [], 'error' => null],
            'cos' => $this->section(true, fn (): array => [
                'overview' => $this->operations->overview($organizationId, 12),
                'health' => $this->operations->health(),
            ]),
        ];
    }

    /** @return array{available: bool, items: list<array<string, mixed>>, error: ?string} */
    private function moduleSnapshot(string $organizationId): array
    {
        try {
            return [
                'available' => true,
                'items' => $this->modules->describe($organizationId),
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'items' => [],
                'error' => 'Стан модулів тимчасово недоступний.',
            ];
        }
    }

    /** @return array{enabled: bool, available: bool, data: array<string, mixed>, error: ?string} */
    private function section(bool $enabled, callable $loader): array
    {
        if (!$enabled) {
            return ['enabled' => false, 'available' => false, 'data' => [], 'error' => null];
        }

        try {
            $data = $loader();
            return ['enabled' => true, 'available' => true, 'data' => is_array($data) ? $data : [], 'error' => null];
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
