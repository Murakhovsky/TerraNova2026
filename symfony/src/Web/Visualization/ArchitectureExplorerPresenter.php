<?php

declare(strict_types=1);

namespace App\Web\Visualization;

use App\Web\Visualization\ViewModel\ArchitectureExplorerViewModel;

final class ArchitectureExplorerPresenter
{
    /**
     * @param array<string,mixed> $data
     * @param array{stage:string,exception:string,detail:string,message:string}|null $diagnostic
     */
    public function present(
        array $data,
        ?array $diagnostic = null,
        ?string $error = null,
    ): ArchitectureExplorerViewModel {
        $graph = $this->array($data['graph'] ?? null);
        $canonicalSummary = $this->array($graph['summary'] ?? null);
        $descriptions = $this->array($data['descriptions'] ?? null);
        $defaultView = (string) ($graph['default_view'] ?? '');
        $views = $this->array($graph['views'] ?? null);
        $defaultPayload = $this->array($views[$defaultView] ?? null);
        $summary = $this->array($defaultPayload['summary'] ?? null);
        if ($summary === []) {
            $summary = $canonicalSummary;
        }

        $health = $this->array($data['health'] ?? null);
        $healthStatus = strtolower((string) ($health['status'] ?? 'unknown'));
        $graphJson = json_encode(
            $graph,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return new ArchitectureExplorerViewModel(
            viewDescriptions: $descriptions,
            defaultView: $defaultView,
            defaultViewLabel: (string) ($this->array($descriptions[$defaultView] ?? null)['label'] ?? $defaultView),
            nodeCount: (int) ($summary['nodes'] ?? 0),
            edgeCount: (int) ($summary['edges'] ?? 0),
            nodeTypes: $this->integerMap($summary['node_types'] ?? null),
            domains: $this->list($canonicalSummary['domains'] ?? null),
            healthStatus: $healthStatus !== '' ? $healthStatus : 'unknown',
            healthErrors: (int) ($health['errors'] ?? 0),
            healthWarnings: (int) ($health['warnings'] ?? 0),
            healthInfo: (int) ($health['info'] ?? 0),
            healthIssues: array_slice($this->list($health['issues'] ?? null), 0, 5),
            graphJson: is_string($graphJson)
                ? $graphJson
                : '{"views":{},"summary":{},"default_view":""}',
            diagnostic: $diagnostic,
            error: $error,
        );
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return array<string,int> */
    private function integerMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $count) {
            if (is_string($key)) {
                $result[$key] = (int) $count;
            }
        }

        return $result;
    }
}
