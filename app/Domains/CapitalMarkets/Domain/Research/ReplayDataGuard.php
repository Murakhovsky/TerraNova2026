<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final class ReplayDataGuard
{
    public function assertAvailableAt(DateTimeImmutable $simulatedAt,DateTimeImmutable $availableAt,string $label='data'):void
    {
        if($availableAt>$simulatedAt){
            throw new InvalidArgumentException('LOOK_AHEAD_BIAS: '.$label.' is not available at simulated timestamp.');
        }
    }

    public function filterAvailable(DateTimeImmutable $simulatedAt,array $events):array
    {
        return array_values(array_filter($events,static function(array $event) use($simulatedAt):bool{
            $available=(string)($event['available_at']??$event['timestamp']??'');
            if($available==='')return false;
            return new DateTimeImmutable($available)<=$simulatedAt;
        }));
    }

    public function assertTransactionCosts(array $configuration):void
    {
        foreach(['fees','slippage'] as $required){
            if(!array_key_exists($required,$configuration)){
                throw new InvalidArgumentException('Backtest without realistic '.$required.' is INVALID FOR PROMOTION.');
            }
        }
    }
}
