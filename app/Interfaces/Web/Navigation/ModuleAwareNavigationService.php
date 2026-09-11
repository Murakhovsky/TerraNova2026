<?php
declare(strict_types=1);

namespace Interfaces\Web\Navigation;

use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\OrganizationContextInterface;
use RuntimeException;

final readonly class ModuleAwareNavigationService
{
    /** @var list<array{module_id: string, service: ModuleNavigationContributorInterface}> */
    private array $contributors;

    /** @param list<array{module_id: string, service: ModuleNavigationContributorInterface}> $contributors */
    public function __construct(
        private OrganizationContextInterface $organization,
        private ActiveModuleResolver $modules,
        array $contributors,
    ) {
        $moduleIds = [];
        $normalized = [];
        foreach ($contributors as $contribution) {
            $moduleId = $contribution['module_id'] ?? null;
            $contributor = $contribution['service'] ?? null;
            if (!is_string($moduleId) || !$contributor instanceof ModuleNavigationContributorInterface) {
                throw new InvalidArgumentException('Invalid Web module navigation contribution.');
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
                throw new InvalidArgumentException(sprintf('Invalid Web navigation module id: %s.', $moduleId));
            }
            if ($contributor->moduleId() !== $moduleId) {
                throw new InvalidArgumentException(sprintf(
                    'Web navigation contributor reports module %s but is owned by %s.',
                    $contributor->moduleId(),
                    $moduleId,
                ));
            }
            if (isset($moduleIds[$moduleId])) {
                throw new InvalidArgumentException(sprintf('Duplicate Web navigation contributor for module: %s.', $moduleId));
            }
            $moduleIds[$moduleId] = true;
            $normalized[] = ['module_id' => $moduleId, 'service' => $contributor];
        }

        $this->contributors = $normalized;
    }

    /** @return array<string, mixed> */
    public function workspace(string $role): array
    {
        $navigation = FrontendNavigation::workspaceCore($role);
        $primary = (array) ($navigation['primary'] ?? []);
        $extensions = [];
        $organizationId = $this->organization->id();

        foreach ($this->contributors as $contribution) {
            $moduleId = $contribution['module_id'];
            $contributor = $contribution['service'];
            if (!$this->modules->isEnabled($organizationId, $moduleId)) {
                continue;
            }

            array_push($primary, ...$contributor->workspacePrimary($role));
            foreach ($contributor->workspaceChildExtensions($role) as $parentKey => $items) {
                $extensions[$parentKey] ??= [];
                array_push($extensions[$parentKey], ...$items);
            }
        }

        $primary = $this->mergeChildExtensions($primary, $extensions);
        $this->assertUniqueKeys($primary, 'workspace primary');

        return [
            'surface' => 'workspace',
            'primary' => $this->normalizeItems($primary),
            'utility' => $this->normalizeItems((array) ($navigation['utility'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    public function portal(string $role): array
    {
        $navigation = FrontendNavigation::portalCore($role);
        $primary = (array) ($navigation['primary'] ?? []);
        $organizationId = $this->organization->id();

        foreach ($this->contributors as $contribution) {
            if ($this->modules->isEnabled($organizationId, $contribution['module_id'])) {
                array_push($primary, ...$contribution['service']->portalPrimary($role));
            }
        }

        $this->assertUniqueKeys($primary, 'portal primary');

        return [
            'surface' => 'portal',
            'primary' => $this->normalizeItems($primary),
            'utility' => $this->normalizeItems((array) ($navigation['utility'] ?? [])),
        ];
    }

    /**
     * @param list<array<string, mixed>> $primary
     * @param array<string, list<array<string, mixed>>> $extensions
     * @return list<array<string, mixed>>
     */
    private function mergeChildExtensions(array $primary, array $extensions): array
    {
        if ($extensions === []) {
            return $primary;
        }

        $seenParents = [];
        foreach ($primary as &$section) {
            $key = (string) ($section['key'] ?? '');
            if (!isset($extensions[$key])) {
                continue;
            }

            $children = array_merge((array) ($section['children'] ?? []), $extensions[$key]);
            $this->assertUniqueKeys($children, sprintf('workspace child section %s', $key));
            $section['children'] = $children;
            $seenParents[$key] = true;
        }
        unset($section);

        foreach (array_keys($extensions) as $parentKey) {
            if (!isset($seenParents[$parentKey])) {
                throw new RuntimeException(sprintf('Web navigation extension target does not exist: %s.', $parentKey));
            }
        }

        return $primary;
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function normalizeItems(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => ((int) ($left['order'] ?? 1000)) <=> ((int) ($right['order'] ?? 1000)));

        foreach ($items as &$item) {
            unset($item['order']);
            if (isset($item['children'])) {
                $children = (array) $item['children'];
                $this->assertUniqueKeys($children, sprintf('children of %s', (string) ($item['key'] ?? '?')));
                $item['children'] = $this->normalizeItems($children);
            }
        }
        unset($item);

        return array_values($items);
    }

    /** @param list<array<string, mixed>> $items */
    private function assertUniqueKeys(array $items, string $scope): void
    {
        $keys = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '') {
                throw new RuntimeException(sprintf('Web navigation item without key in %s.', $scope));
            }
            if (isset($keys[$key])) {
                throw new RuntimeException(sprintf('Duplicate Web navigation key %s in %s.', $key, $scope));
            }
            $keys[$key] = true;
        }
    }
}
