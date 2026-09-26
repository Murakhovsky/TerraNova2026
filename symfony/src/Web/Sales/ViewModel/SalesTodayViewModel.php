<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesTodayViewModel
{
    /**
     * @param list<array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   eyebrow:string,
     *   items:list<array<string,mixed>>
     * }> $sections
     */
    public function __construct(
        public string $scope,
        public array $sections,
        public int $total,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->total === 0 ? 'empty' : 'normal';
    }
}
