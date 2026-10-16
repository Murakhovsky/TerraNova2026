<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use InvalidArgumentException;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\GraphProviderInterface;

/**
 * Conservative source-evidence-only impact projection over the EXISTING architecture graph.
 * Does not claim to discover dynamic SQL, reflection, runtime calls, or every consumer.
 */
final readonly class ArchitectureImpactAnalysisService
{
    public function __construct(private GraphProviderInterface $graph)
    {
    }

    /**
     * @param list<string> $changedNodeIds
     * @return array<string,mixed>
     */
    public function analyze(array $changedNodeIds): array
    {
        if ($changedNodeIds === []) {
            throw new InvalidArgumentException('Impact analysis requires at least one changed architecture node.');
        }
        $graph = $this->graph->provide();
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $nodes[$node->id] = $node;
        }
        $reverse = [];
        $owners = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->relation === ArchitectureGraphVocabulary::REL_OWNS) {
                $owners[$edge->target][] = $edge->source;
            }
            if (in_array($edge->relation, [
                ArchitectureGraphVocabulary::REL_DEPENDS_ON,
                ArchitectureGraphVocabulary::REL_REQUIRES_CONTRACT,
                ArchitectureGraphVocabulary::REL_HANDLED_BY,
            ], true)) {
                $reverse[$edge->target][] = $edge;
            }
        }

        $unknown = [];
        $queue = [];
        $seen = [];
        foreach (array_unique($changedNodeIds) as $id) {
            if (!is_string($id) || $id === '') {
                throw new InvalidArgumentException('Invalid changed architecture node identifier.');
            }
            if (!isset($nodes[$id])) {
                $unknown[] = $id;
                continue;
            }
            $queue[] = [$id, 0];
            $seen[$id] = 0;
        }

        $evidence = [];
        $direct = [];
        $transitive = [];
        for ($i = 0; $i < count($queue); $i++) {
            [$at, $depth] = $queue[$i];
            foreach ($reverse[$at] ?? [] as $edge) {
                if (!$edge instanceof Edge) {
                    continue;
                }
                $consumer = $edge->source;
                $newDepth = $depth + 1;
                if (isset($seen[$consumer]) && $seen[$consumer] <= $newDepth) {
                    continue;
                }
                $seen[$consumer] = $newDepth;
                $queue[] = [$consumer, $newDepth];
                $item = [
                    'node_id' => $consumer,
                    'type' => $nodes[$consumer]->type,
                    'via' => $at,
                    'relation' => $edge->relation,
                    'source_evidence' => $edge->metadata['source'] ?? 'graph-declaration',
                    'confidence' => 'declared',
                    'distance' => $newDepth,
                ];
                $evidence[$consumer] = $item;
                if ($newDepth === 1) {
                    $direct[$consumer] = true;
                } else {
                    $transitive[$consumer] = true;
                }
            }
        }
        $affected = array_keys($evidence);
        sort($affected);
        $domains = [];
        $capabilities = [];
        $tests = [];
        foreach (array_merge(array_keys($seen), $changedNodeIds) as $id) {
            $node = $nodes[$id] ?? null;
            if ($node === null) {
                continue;
            }
            if ($node->type === ArchitectureGraphVocabulary::TYPE_DOMAIN) {
                $domains[$id] = true;
            }
            if ($node->type === ArchitectureGraphVocabulary::TYPE_CAPABILITY) {
                $capabilities[$id] = true;
            }
            foreach ($owners[$id] ?? [] as $owner) {
                if (str_starts_with($owner, 'domain:')) {
                    $domains[$owner] = true;
                }
            }
            foreach (($node->metadata['tests'] ?? []) as $test) {
                if (is_string($test) && $test !== '') {
                    $tests[$test] = true;
                }
            }
        }
        $directIds = array_keys($direct);
        $transitiveIds = array_keys($transitive);
        sort($directIds);
        sort($transitiveIds);
        ksort($evidence);
        $domainIds = array_keys($domains);
        $capabilityIds = array_keys($capabilities);
        $testIds = array_keys($tests);
        sort($domainIds);
        sort($capabilityIds);
        sort($testIds);

        return [
            'schema_version' => '1.0.0',
            'changed' => array_values(array_unique($changedNodeIds)),
            'direct_dependents' => $directIds,
            'transitive_dependents' => $transitiveIds,
            'affected_nodes' => $affected,
            'affected_domains' => $domainIds,
            'affected_capabilities' => $capabilityIds,
            'recommended_tests' => $testIds,
            'dependency_evidence' => array_values($evidence),
            'unknown_nodes' => $unknown,
            'compatibility_verdict' => 'UNKNOWN_REQUIRES_CONTRACT_TESTS',
            'risk' => $unknown !== [] ? 'UNKNOWN_INPUT' : 'REVIEW_REQUIRED',
            'coverage' => 'DECLARED_STATIC_EDGES_ONLY',
            'unresolved' => [
                'runtime traces not ingested',
                'dynamic SQL/reflection dependencies not proven',
                'files-to-nodes and consumer-driven test mapping incomplete',
            ],
            'rollback_considerations' => [
                'Review external side effects before retry or rollback.',
                'Use additive schema migration and preserved execution history.',
            ],
        ];
    }
}
