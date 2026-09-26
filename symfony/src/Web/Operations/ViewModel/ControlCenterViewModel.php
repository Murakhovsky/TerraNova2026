<?php

declare(strict_types=1);

namespace App\Web\Operations\ViewModel;

final readonly class ControlCenterViewModel
{
    /**
     * @param list<array{label:string,value:string,hint?:string,tone?:string}> $kpis
     * @param list<array{
     *   id:string,
     *   eyebrow:string,
     *   title:string,
     *   description:string,
     *   items:list<array<string,mixed>>
     * }> $sections
     */
    public function __construct(
        public array $kpis,
        public array $sections,
        public string $actionStatus = '',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
