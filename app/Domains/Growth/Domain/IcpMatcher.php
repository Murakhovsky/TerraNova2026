<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;

final readonly class IcpMatcher
{
    public const MODEL_VERSION='growth-icp-v1';

    public function match(IcpProfile $profile,AccountSnapshot $snapshot,DateTimeImmutable $scoredAt): AccountIcpMatch
    {
        $scores=[];
        $matched=[];
        $gaps=[];
        $criteria=$profile->criteria;

        if($criteria->industries!==[]){
            $actual=strtolower(trim((string)($snapshot->firmographics['industry']??'')));
            $ok=$actual!==''&&in_array($actual,array_map('strtolower',$criteria->industries),true);
            $scores[]=$ok?100:0;
            if($ok)$matched[]='industry'; else $gaps[]='industry';
        }

        if($criteria->regions!==[]){
            $actual=strtolower(trim((string)($snapshot->firmographics['region']??$snapshot->firmographics['country']??'')));
            $ok=$actual!==''&&in_array($actual,array_map('strtolower',$criteria->regions),true);
            $scores[]=$ok?100:0;
            if($ok)$matched[]='region'; else $gaps[]='region';
        }

        if($criteria->minEmployees!==null||$criteria->maxEmployees!==null){
            $raw=$snapshot->firmographics['employee_count']??null;
            $count=is_numeric($raw)?(int)$raw:null;
            $ok=$count!==null
                &&($criteria->minEmployees===null||$count>=$criteria->minEmployees)
                &&($criteria->maxEmployees===null||$count<=$criteria->maxEmployees);
            $scores[]=$ok?100:0;
            if($ok)$matched[]='employee_range'; else $gaps[]='employee_range';
        }

        if($criteria->technologies!==[]){
            $actual=array_map('strtolower',$snapshot->technologies);
            $hits=count(array_intersect(array_map('strtolower',$criteria->technologies),$actual));
            $score=(int)round(100*$hits/count($criteria->technologies));
            $scores[]=$score;
            if($score===100)$matched[]='technologies'; else $gaps[]='technologies';
        }

        if($criteria->requiredSignalTypes!==[]){
            $actual=array_map('strtolower',$snapshot->signalTypes);
            $hits=count(array_intersect(array_map('strtolower',$criteria->requiredSignalTypes),$actual));
            $score=(int)round(100*$hits/count($criteria->requiredSignalTypes));
            $scores[]=$score;
            if($score===100)$matched[]='signals'; else $gaps[]='signals';
        }

        $fit=(int)round(array_sum($scores)/count($scores));
        return new AccountIcpMatch(
            $snapshot->accountId,$profile->id,$profile->revision,
            new ScoreDimension(
                $fit,
                sprintf('Matched %d of %d configured ICP groups.',count($matched),count($scores)),
                $snapshot->sourceReferences,
                self::MODEL_VERSION,
            ),
            $matched,$gaps,$scoredAt,
        );
    }
}
