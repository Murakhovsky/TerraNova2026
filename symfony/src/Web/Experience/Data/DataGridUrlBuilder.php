<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

final class DataGridUrlBuilder
{
    /** @param array<string,mixed> $overrides */
    public static function build(string $baseUrl, DataGridQuery $query, array $overrides = []): string
    {
        $state = $query->with($overrides);
        $params = $state->parameters();

        if ($params === []) {
            return $baseUrl;
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($params);
    }

    private function __construct()
    {
    }
}
