<?php
declare(strict_types=1);

namespace Platform\Knowledge\Service;

use DateTimeImmutable;
use Platform\Knowledge\Contract\ContextBuilderInterface;
use Platform\Knowledge\Contract\RetrieverInterface;
use Platform\Knowledge\Model\Context;
use Platform\Knowledge\Model\ContextRequest;
use Platform\Knowledge\Model\RetrievalResult;

final readonly class ContextBuilder implements ContextBuilderInterface
{
    public function __construct(private RetrieverInterface $retriever)
    {
    }

    public function build(ContextRequest $request): Context
    {
        $candidates = $this->retriever->retrieve($request);
        usort($candidates, static fn (RetrievalResult $a, RetrievalResult $b): int => $b->score <=> $a->score);

        $selected = [];
        $seen = [];
        $tokens = 0;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof RetrievalResult || isset($seen[$candidate->chunkId])) {
                continue;
            }
            if (count($selected) >= $request->limit) {
                break;
            }
            if ($tokens + $candidate->tokenCount > $request->maxTokens) {
                continue;
            }
            $seen[$candidate->chunkId] = true;
            $selected[] = $candidate;
            $tokens += $candidate->tokenCount;
        }

        return new Context(
            organizationId: $request->organizationId,
            query: $request->query,
            results: $selected,
            tokenCount: $tokens,
            builtAt: new DateTimeImmutable(),
            metadata: [
                'candidate_count' => count($candidates),
                'selected_count' => count($selected),
                'correlation_id' => $request->correlationId,
            ],
        );
    }
}
