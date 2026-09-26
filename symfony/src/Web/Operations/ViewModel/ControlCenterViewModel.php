<?php

declare(strict_types=1);

namespace App\Web\Operations\ViewModel;

final readonly class ControlCenterViewModel
{
    /**
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param list<array{
     *   key:string,
     *   eyebrow:string,
     *   label:string,
     *   description:string,
     *   items:list<array{
     *     title:string,
     *     subtitle:string,
     *     meta:string,
     *     statusLabel:?string,
     *     statusTone:string
     *   }>
     * }> $groups
     * @param list<array<string,mixed>> $actions
     * @param list<array<string,mixed>> $approvals
     */
    public function __construct(
        public array $kpis,
        public array $groups,
        public array $actions,
        public array $approvals,
        public string $actionStatus = '',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
