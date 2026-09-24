<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PropertySubmissionsViewModel
{
    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,int> $counts
     * @param list<array{code:string,label:string,count:int}> $statusOptions
     */
    public function __construct(
        public string $status,
        public array $items,
        public array $counts,
        public array $statusOptions,
        public int $total,
        public int $page,
        public int $perPage,
        public ?string $previousUrl,
        public ?string $nextUrl,
        public ?string $error=null,
    ) {
    }

    public function state(): string
    {
        if($this->error!==null)return 'error';
        return $this->total===0?'empty':'normal';
    }

    public function newCount(): int
    {
        return (int)($this->counts['new']??0)+(int)($this->counts['submitted']??0);
    }
}
