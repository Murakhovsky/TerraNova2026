<?php

declare(strict_types=1);

namespace App\Web\Visualization;

use App\Web\Visualization\ViewModel\ArchitectureOverviewViewModel;

final class ArchitectureOverviewPresenter
{
    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): ArchitectureOverviewViewModel
    {
        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $descriptions = is_array($data['views'] ?? null) ? $data['views'] : [];
        $views = [];

        foreach ($descriptions as $name => $description) {
            if (!is_array($description)) {
                continue;
            }

            $views[] = [
                'name' => (string) $name,
                'label' => (string) ($description['label'] ?? $name),
                'layout' => (string) ($description['layout'] ?? 'auto'),
            ];
        }

        return new ArchitectureOverviewViewModel(
            defaultView: (string) ($data['default_view'] ?? ($views[0]['name'] ?? '')),
            views: $views,
            summary: $summary,
            domains: array_values(array_filter($summary['domains'] ?? [], 'is_array')),
            health: is_array($data['health'] ?? null) ? $data['health'] : [],
            error: $error,
        );
    }
}
