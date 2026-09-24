<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PropertySubmissionViewModel
{
    /**
     * @param list<array{label:string,value:string}> $meta
     * @param list<array{label:string,value:string,hint:string}> $kpis
     * @param list<array{label:string,value:string}> $details
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $subtitle,
        public string $identity,
        public string $statusLabel,
        public string $statusTone,
        public array $meta,
        public array $kpis,
        public array $details,
        public string $description,
        public string $reviewNote,
        public ?string $error=null,
    ) {
    }

    public function state(): string
    {
        return $this->error!==null?'error':'normal';
    }
}
