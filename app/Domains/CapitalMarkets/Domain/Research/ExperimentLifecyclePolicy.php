<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final class ExperimentLifecyclePolicy
{
    private const TRANSITIONS=[
        'DRAFT'=>['QUEUED','CANCELLED','INVALIDATED'],
        'QUEUED'=>['RUNNING','CANCELLED','INVALIDATED'],
        'RUNNING'=>['COMPLETED','FAILED','CANCELLED','INVALIDATED'],
        'FAILED'=>['QUEUED','INVALIDATED'],
        'CANCELLED'=>['QUEUED','INVALIDATED'],
        'COMPLETED'=>['INVALIDATED'],
        'INVALIDATED'=>[],
    ];

    public function assertTransition(string $from,string $to):void
    {
        $from=strtoupper(trim($from));$to=strtoupper(trim($to));
        if(!array_key_exists($from,self::TRANSITIONS)||!in_array($to,self::TRANSITIONS[$from],true)){
            throw new InvalidArgumentException('Unsupported experiment transition: '.$from.' -> '.$to);
        }
    }
}
