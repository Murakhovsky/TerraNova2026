<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final class ResearchIsolationPolicy
{
    public function assertPartitions(array $train,array $validation,array $oos):void
    {
        $this->assertWindow($train,'TRAIN');
        $this->assertWindow($validation,'VALIDATION');
        $this->assertWindow($oos,'OUT_OF_SAMPLE');

        $trainTo=new DateTimeImmutable((string)$train['to']);
        $validationFrom=new DateTimeImmutable((string)$validation['from']);
        $validationTo=new DateTimeImmutable((string)$validation['to']);
        $oosFrom=new DateTimeImmutable((string)$oos['from']);

        if($trainTo>$validationFrom||$validationTo>$oosFrom){
            throw new InvalidArgumentException('TRAIN/VALIDATION/OOS windows must not overlap.');
        }
    }

    public function assertOosFrozen(array $experiment,array $current):void
    {
        foreach(['strategy_version_id','parameters_hash','success_criteria_hash','failure_criteria_hash','dataset_id'] as $field){
            if(($experiment[$field]??null)!==($current[$field]??null)){
                throw new InvalidArgumentException('OOS isolation breach: '.$field.' changed after freeze.');
            }
        }
    }

    public function assertNewOosPeriod(array $previous,array $next):void
    {
        if(
            ($previous['from']??null)===($next['from']??null)
            &&($previous['to']??null)===($next['to']??null)
        ){
            throw new InvalidArgumentException('Failed OOS cannot be reused after tuning; a new OOS period is required.');
        }
    }

    private function assertWindow(array $window,string $name):void
    {
        if(!isset($window['from'],$window['to']))throw new InvalidArgumentException($name.' window is incomplete.');
        $from=new DateTimeImmutable((string)$window['from']);
        $to=new DateTimeImmutable((string)$window['to']);
        if($to<=$from)throw new InvalidArgumentException($name.' window is invalid.');
    }
}
