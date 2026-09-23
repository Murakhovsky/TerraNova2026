<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesDealViewModel
{
    /**
     * @param list<array{label:string,value:string}> $meta
     * @param list<array{label:string,value:string}> $kpis
     * @param list<array{id:string,label:string,selected:bool}> $stages
     * @param list<array{id:int,label:string,selected:bool}> $owners
     * @param list<array<string,string>> $communications
     * @param list<array{id:string,type:string,reason:string}> $approvals
     * @param list<array{tone:string,time:string,title:string,copy:string}> $timeline
     * @param list<array{label:string,value:string}> $intelligenceMetrics
     */
    public function __construct(
        public int $id,
        public string $identity,
        public string $title,
        public string $subtitle,
        public string $statusLabel,
        public string $statusTone,
        public array $meta,
        public array $kpis,
        public string $nextContact,
        public string $attentionReason,
        public string $customer,
        public string $phone,
        public string $email,
        public string $telegram,
        public array $stages,
        public array $owners,
        public string $priority,
        public string $nextContactInput,
        public array $communications,
        public array $approvals,
        public array $timeline,
        public array $intelligenceMetrics,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}
