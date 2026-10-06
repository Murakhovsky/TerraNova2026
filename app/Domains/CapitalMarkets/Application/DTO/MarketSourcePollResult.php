<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\DTO;

final readonly class MarketSourcePollResult
{
    /** @param list<string> $errors */
    public function __construct(
        public string $status,
        public int $targets,
        public int $rawEvents,
        public int $accepted,
        public int $duplicates,
        public int $failed,
        public array $errors=[],
    ){}

    /** @return array<string,mixed> */
    public function toArray():array{return [
        'status'=>$this->status,
        'targets'=>$this->targets,
        'raw_events'=>$this->rawEvents,
        'accepted'=>$this->accepted,
        'duplicates'=>$this->duplicates,
        'failed'=>$this->failed,
        'errors'=>$this->errors,
    ];}
}
