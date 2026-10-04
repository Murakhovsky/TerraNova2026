<?php

declare(strict_types=1);

namespace App\Web\Experience\Quality;

use InvalidArgumentException;

final readonly class PageQualityScore
{
    public function __construct(
        public int $functionality = 0,
        public int $ux = 0,
        public int $visual = 0,
        public int $responsive = 0,
        public int $accessibility = 0,
        public int $content = 0,
    ) {
        foreach ($this->toArray() as $dimension => $score) {
            if ($score < 0 || $score > 4) {
                throw new InvalidArgumentException(sprintf('%s score must be between 0 and 4.', $dimension));
            }
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            functionality: (int) ($data['functionality'] ?? 0),
            ux: (int) ($data['ux'] ?? 0),
            visual: (int) ($data['visual'] ?? 0),
            responsive: (int) ($data['responsive'] ?? 0),
            accessibility: (int) ($data['accessibility'] ?? 0),
            content: (int) ($data['content'] ?? 0),
        );
    }

    public function isV1Ready(): bool
    {
        return min($this->toArray()) >= 3;
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            'functionality' => $this->functionality,
            'ux' => $this->ux,
            'visual' => $this->visual,
            'responsive' => $this->responsive,
            'accessibility' => $this->accessibility,
            'content' => $this->content,
        ];
    }
}
