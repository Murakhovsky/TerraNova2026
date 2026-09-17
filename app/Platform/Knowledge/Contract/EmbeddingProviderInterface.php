<?php
declare(strict_types=1);

namespace Platform\Knowledge\Contract;

interface EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text, string $model): array;
}
