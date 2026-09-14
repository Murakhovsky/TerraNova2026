<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyPresentationInterface
{
    public function generate(string $slug, string $variant = 'client'): ?array;
    public function recordDownload(array $document, ?array $user, string $sourcePage): void;
    public function registerShare(array $input, ?array $user = null): array;
}
