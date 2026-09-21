<?php

declare(strict_types=1);

namespace App\Web\Experience\Search;

use App\Web\Experience\Extension\Model\SearchResult;

final readonly class SearchResultMatcher
{
    /**
     * @param list<SearchResult> $items
     * @return list<SearchResult>
     */
    public function match(array $items, string $query, int $limit = 10): array
    {
        $query = $this->normalize($query);
        $limit = max(1, min(50, $limit));

        if ($query === '') {
            return array_slice($items, 0, $limit);
        }

        $ranked = [];

        foreach ($items as $item) {
            $score = $this->score($item, $query);
            if ($score <= 0.0) {
                continue;
            }

            $ranked[] = new SearchResult(
                id: $item->id,
                label: $item->label,
                path: $item->path,
                kind: $item->kind,
                subtitle: $item->subtitle,
                entity: $item->entity,
                score: max($item->score, $score),
            );
        }

        usort(
            $ranked,
            static fn (SearchResult $left, SearchResult $right): int
                => [-$left->score, $left->label, $left->id]
                <=> [-$right->score, $right->label, $right->id],
        );

        return array_slice($ranked, 0, $limit);
    }

    private function score(SearchResult $item, string $query): float
    {
        $label = $this->normalize($item->label);
        $subtitle = $this->normalize($item->subtitle ?? '');
        $id = $this->normalize($item->id);

        if ($label === $query) {
            return 100.0;
        }

        if (str_starts_with($label, $query)) {
            return 85.0;
        }

        if ($this->wordStartsWith($label, $query)) {
            return 75.0;
        }

        if (str_contains($label, $query)) {
            return 65.0;
        }

        if ($subtitle !== '' && str_contains($subtitle, $query)) {
            return 45.0;
        }

        if (str_contains($id, $query)) {
            return 35.0;
        }

        return 0.0;
    }

    private function wordStartsWith(string $value, string $query): bool
    {
        foreach (preg_split('/[^a-z0-9._:-]+/', $value) ?: [] as $word) {
            if ($word !== '' && str_starts_with($word, $query)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
