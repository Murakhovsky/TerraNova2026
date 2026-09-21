<?php

declare(strict_types=1);

namespace App\Web\Experience\Search;

use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Shell\CoreCommandCatalog;
use App\Web\Experience\Shell\ShellCommandItem;

final readonly class GlobalSearchService
{
    public function __construct(
        private WebExtensionCatalog $extensions,
        private SearchResultMatcher $matcher,
        private CoreCommandCatalog $coreCommands,
    ) {
    }

    public function search(
        WebExtensionContext $context,
        string $query,
        int $limit = 20,
    ): GlobalSearchResultSet {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        $catalog = $this->extensions->forContext($context);

        $commands = [
            ...$this->coreCommands->commands($context),
            ...$catalog->commands(),
        ];

        $commandResults = array_map(
            static fn (ShellCommandItem $command): SearchResult => new SearchResult(
                id: 'command:' . $command->id,
                label: $command->label,
                path: $command->path,
                kind: 'command',
                subtitle: $command->hint,
                score: 0.0,
            ),
            $commands,
        );

        $commandMatches = $this->matcher->match($commandResults, $query, $limit);

        if ($query === '') {
            return new GlobalSearchResultSet($query, $commandMatches, $limit);
        }

        $providerResults = $catalog->search($query, $limit);
        $merged = $this->deduplicate([...$commandMatches, ...$providerResults]);

        usort(
            $merged,
            static fn (SearchResult $left, SearchResult $right): int
                => [-$left->score, $left->kind, $left->label, $left->id]
                <=> [-$right->score, $right->kind, $right->label, $right->id],
        );

        return new GlobalSearchResultSet(
            $query,
            array_slice($merged, 0, $limit),
            $limit,
        );
    }

    /**
     * @param list<SearchResult> $items
     * @return list<SearchResult>
     */
    private function deduplicate(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $key = $item->path . "\0" . $item->label;
            if (!isset($unique[$key]) || $item->score > $unique[$key]->score) {
                $unique[$key] = $item;
            }
        }

        return array_values($unique);
    }
}
