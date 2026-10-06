<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use DomainException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionModePolicy extends ValueObject
{
    public function __construct(
        public bool $manualLiveEnabled=false,
        public bool $autoLiveEnabled=false,
    ){}

    public function assertPaperOnly():void
    {
        if($this->manualLiveEnabled||$this->autoLiveEnabled){
            throw new DomainException('LIVE_EXECUTION_DISABLED_FOR_TOKENIZED_EQUITY_VS1');
        }
    }

    public function allowsLive():bool
    {
        return $this->manualLiveEnabled||$this->autoLiveEnabled;
    }
}
