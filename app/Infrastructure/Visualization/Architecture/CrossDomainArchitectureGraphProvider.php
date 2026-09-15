<?php
declare(strict_types=1);

namespace Infrastructure\Visualization\Architecture;

use Kernel\Module\CrossDomainContract;
use Kernel\Module\ModuleCatalog;
use Kernel\Visualization\Graph\Edge;
use Kernel\Visualization\Graph\Graph;
use Kernel\Visualization\Graph\GraphProviderInterface;
use Kernel\Visualization\Graph\Node;
use RuntimeException;

final readonly class CrossDomainArchitectureGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private GraphProviderInterface $inner,
        private ModuleCatalog $modules,
    ) {
    }

    public function provide(): Graph
    {
        $base = $this->inner->provide();
        $nodes = [];
        $edges = [];

        foreach ($base->nodes() as $node) {
            $nodes[$node->id] = $node;
        }
        foreach ($base->edges() as $edge) {
            $edges[$edge->id] = $edge;
        }

        $kernelId = 'kernel:cos';
        foreach ($this->modules->definitions() as $definition) {
            $declaringDomain = $definition->manifest->id;
            foreach ($definition->contributions->crossDomainContracts as $contract) {
                if (!$contract instanceof CrossDomainContract) {
                    continue;
                }

                $consumer = $contract->consumerDomain($declaringDomain);
                $provider = $contract->providerDomain($declaringDomain);
                $owner = $this->contractOwner($contract->contract);

                $this->ensureDomain($nodes, $edges, $kernelId, $consumer);
                $this->ensureDomain($nodes, $edges, $kernelId, $provider);
                if ($owner !== null) {
                    $this->ensureDomain($nodes, $edges, $kernelId, $owner);
                }

                $contractId = 'contract:' . sha1($contract->contract);
                $metadata = [
                    'contract' => $contract->contract,
                    'kind' => $contract->kind,
                    'purpose' => $contract->purpose,
                    'consumer_domain' => $consumer,
                    'provider_domain' => $provider,
                    'owner_domain' => $owner,
                    'declared_by' => $declaringDomain,
                    'declaration_role' => $contract->role,
                    'source' => 'module.contributions.cross_domain_contracts',
                ];

                if (isset($nodes[$contractId])) {
                    $existing = $nodes[$contractId]->metadata;
                    if (
                        ($existing['consumer_domain'] ?? null) !== $consumer
                        || ($existing['provider_domain'] ?? null) !== $provider
                    ) {
                        throw new RuntimeException(sprintf(
                            'Conflicting cross-domain contract declaration for %s.',
                            $contract->contract,
                        ));
                    }
                } else {
                    $nodes[$contractId] = new Node(
                        $contractId,
                        ArchitectureGraphVocabulary::TYPE_CONTRACT,
                        $this->shortName($contract->contract),
                        metadata: $metadata,
                    );
                }

                if ($owner !== null) {
                    $this->addEdge(
                        $edges,
                        $this->domainId($owner),
                        $contractId,
                        ArchitectureGraphVocabulary::REL_OWNS,
                        ['source' => 'contract.namespace_ownership'],
                    );
                }
                $this->addEdge(
                    $edges,
                    $this->domainId($consumer),
                    $contractId,
                    ArchitectureGraphVocabulary::REL_REQUIRES_CONTRACT,
                    ['source' => 'module.contributions.cross_domain_contracts', 'kind' => $contract->kind],
                );
                $this->addEdge(
                    $edges,
                    $this->domainId($provider),
                    $contractId,
                    ArchitectureGraphVocabulary::REL_PROVIDES_CONTRACT,
                    ['source' => 'module.contributions.cross_domain_contracts', 'kind' => $contract->kind],
                );
            }
        }

        return new Graph(array_values($nodes), array_values($edges), $base->groups());
    }

    /** @param array<string,Node> $nodes @param array<string,Edge> $edges */
    private function ensureDomain(array &$nodes, array &$edges, string $kernelId, string $domain): void
    {
        $domainId = $this->domainId($domain);
        if (isset($nodes[$domainId])) {
            return;
        }

        $nodes[$domainId] = new Node(
            $domainId,
            ArchitectureGraphVocabulary::TYPE_DOMAIN,
            ucfirst($domain),
            metadata: [
                'module_id' => $domain,
                'contract_declared_only' => true,
                'source' => 'module.contributions.cross_domain_contracts',
            ],
        );

        if (isset($nodes[$kernelId])) {
            $this->addEdge(
                $edges,
                $kernelId,
                $domainId,
                ArchitectureGraphVocabulary::REL_CONTAINS,
                ['source' => 'cross_domain_contract'],
            );
        }
    }

    private function contractOwner(string $contract): ?string
    {
        if (!preg_match('/^Domains\\\\([A-Z][A-Za-z0-9]*)\\\\/', $contract, $matches)) {
            return null;
        }
        return strtolower($matches[1]);
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
    }

    /** @param array<string,Edge> $edges @param array<string,mixed> $metadata */
    private function addEdge(array &$edges, string $source, string $target, string $relation, array $metadata = []): void
    {
        $id = 'edge:' . sha1($source . "\0" . $relation . "\0" . $target);
        if (isset($edges[$id])) {
            return;
        }
        $edges[$id] = new Edge($id, $source, $target, $relation, $metadata);
    }

    private function domainId(string $domain): string
    {
        return 'domain:' . $domain;
    }
}
