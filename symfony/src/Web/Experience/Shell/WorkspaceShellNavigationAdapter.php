<?php

declare(strict_types=1);

namespace App\Web\Experience\Shell;

final class WorkspaceShellNavigationAdapter
{
    /**
     * Transitional adapter for the current NavigationBuilder array contract.
     *
     * Wave 12.5 replaces the source with Web provider registries without
     * changing ShellViewModel or Twig shell composition.
     *
     * @param array{primary?:list<array<string,mixed>>,utility?:list<array<string,mixed>>} $navigation
     * @return array{primary:list<ShellNavigationItem>,utility:list<ShellNavigationItem>,commands:list<ShellCommandItem>}
     */
    public function adapt(array $navigation, string $activeSection, string $activeItem): array
    {
        $commands = [];

        $primary = $this->items(
            $navigation['primary'] ?? [],
            $activeSection,
            $activeItem,
            $commands,
        );

        $utility = $this->items(
            $navigation['utility'] ?? [],
            $activeSection,
            $activeItem,
            $commands,
        );

        return [
            'primary' => $primary,
            'utility' => $utility,
            'commands' => $commands,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<ShellCommandItem> $commands
     * @return list<ShellNavigationItem>
     */
    private function items(array $items, string $activeSection, string $activeItem, array &$commands): array
    {
        $result = [];

        foreach ($items as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? $key));
            $path = ltrim(trim((string) ($item['path'] ?? '')), '/');

            if ($key === '' || $label === '' || $path === '') {
                continue;
            }

            $children = $this->items(
                is_array($item['children'] ?? null) ? $item['children'] : [],
                $activeSection,
                $activeItem,
                $commands,
            );

            $isActive = $key === $activeSection || $key === $activeItem;

            $result[] = new ShellNavigationItem(
                key: $key,
                label: $label,
                path: '/' . $path,
                glyph: (string) ($item['glyph'] ?? '•'),
                active: $isActive,
                children: $children,
            );

            $commands[] = new ShellCommandItem(
                id: 'navigate.' . str_replace('_', '-', $key),
                label: $label,
                path: '/' . $path,
                kind: 'navigation',
            );
        }

        return $result;
    }
}
