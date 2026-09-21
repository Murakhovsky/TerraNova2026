<?php

declare(strict_types=1);

namespace App\Web\Experience\Realtime;

use InvalidArgumentException;
use Stringable;

final readonly class RealtimeTopic implements Stringable
{
    public function __construct(public string $value)
    {
        $parts = parse_url($this->value);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'realtime.cos.internal'
            || !str_starts_with((string) ($parts['path'] ?? ''), '/organizations/')
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Realtime topic must be a canonical COS realtime IRI.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
