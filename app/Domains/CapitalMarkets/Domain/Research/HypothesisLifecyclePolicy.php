<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final class HypothesisLifecyclePolicy
{
    private const TRANSITIONS=[
        'IDEA'=>['DRAFT','ARCHIVED'],
        'DRAFT'=>['DATA_REQUIRED','READY_FOR_RESEARCH','REJECTED','ARCHIVED'],
        'DATA_REQUIRED'=>['DRAFT','READY_FOR_RESEARCH','REJECTED','ARCHIVED'],
        'READY_FOR_RESEARCH'=>['RESEARCHING','REJECTED','SUSPENDED','ARCHIVED'],
        'RESEARCHING'=>['BACKTESTING','DATA_REQUIRED','REJECTED','SUSPENDED'],
        'BACKTESTING'=>['OUT_OF_SAMPLE','REJECTED','SUSPENDED'],
        'OUT_OF_SAMPLE'=>['PAPER','REJECTED','SUSPENDED'],
        'PAPER'=>['VALIDATED','REJECTED','SUSPENDED'],
        'VALIDATED'=>['SUSPENDED','ARCHIVED'],
        'SUSPENDED'=>['RESEARCHING','PAPER','REJECTED','ARCHIVED'],
        'REJECTED'=>['DRAFT','ARCHIVED'],
        'ARCHIVED'=>[],
    ];

    public function assertTransition(string $from,string $to):void
    {
        $from=strtoupper(trim($from));$to=strtoupper(trim($to));
        if(!array_key_exists($from,self::TRANSITIONS)||!in_array($to,self::TRANSITIONS[$from],true)){
            throw new InvalidArgumentException('Unsupported hypothesis transition: '.$from.' -> '.$to);
        }
    }
}
