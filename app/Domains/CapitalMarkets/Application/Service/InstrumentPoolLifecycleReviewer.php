<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DomainException;
/** Read-only, conservative evidence review. This service NEVER updates venue mappings. */
final readonly class InstrumentPoolLifecycleReviewer
{
    /**
     * @param list<array<string,mixed>> $history Newest first, same tenant/provider/venue.
     * @return array<string,mixed>
     */
    public function review(array $history,int $requiredConsecutive=3):array
    {
        if($requiredConsecutive<2||$requiredConsecutive>7||count($history)>10){
            throw new DomainException('Invalid Instrument Pool lifecycle review scope.');
        }
        if($history===[])return ['status'=>'NO_EVIDENCE','rows'=>[],'required_consecutive'=>$requiredConsecutive];
        $current=$history[0];
        if(!is_array($current)||($current['health']??null)!=='AVAILABLE'
            ||($current['truncated']??null)!==false){
            return ['status'=>'INSUFFICIENT_EVIDENCE','rows'=>[],'required_consecutive'=>$requiredConsecutive];
        }
        $scope=[$current['provider']??null,$current['venue_id']??null];
        if(!is_string($scope[0])||!is_string($scope[1])||!is_array($current['rows']??null)
            ||count($current['rows'])>500){
            throw new DomainException('Malformed Instrument Pool lifecycle snapshot.');
        }
        $rows=[];$seen=[];
        foreach($current['rows'] as $row){
            if(!is_array($row)||!is_string($row['venue_symbol']??null)
                ||!is_string($row['observation']??null)
                ||isset($seen[$row['venue_symbol']])){
                throw new DomainException('Malformed or duplicate Instrument Pool observation.');
            }
            $symbol=$row['venue_symbol'];$seen[$symbol]=true;
            $state=$row['observation'];
            if(!in_array($state,['MAPPED_ACTIVE_OBSERVED','MAPPED_STATUS_REVIEW',
                'MAPPED_NOT_OBSERVED_REVIEW','UNMAPPED_REVIEW'],true)){
                throw new DomainException('Unknown Instrument Pool observation state.');
            }
            if(!in_array($state,['MAPPED_NOT_OBSERVED_REVIEW','UNMAPPED_REVIEW'],true))continue;
            $same=0;
            foreach($history as $snapshot){
                if(!is_array($snapshot)||($snapshot['health']??null)!=='AVAILABLE'
                    ||($snapshot['truncated']??null)!==false
                    ||[$snapshot['provider']??null,$snapshot['venue_id']??null]!==$scope
                    ||!is_array($snapshot['rows']??null))break;
                $found=null;
                foreach($snapshot['rows'] as $observed){
                    if(is_array($observed)&&($observed['venue_symbol']??null)===$symbol){
                        $found=$observed;break;
                    }
                }
                if(!is_array($found)||($found['observation']??null)!==$state
                    ||($found['instrument_id']??null)!==($row['instrument_id']??null))break;
                ++$same;
                if($same===$requiredConsecutive)break;
            }
            $rows[]=[
                'venue_symbol'=>$symbol,'instrument_id'=>$row['instrument_id']??null,
                'observation'=>$state,'consecutive_healthy_observations'=>$same,
                'decision'=>$same>=$requiredConsecutive
                    ?'OPERATOR_REVIEW_REQUIRED':'AWAIT_MORE_EVIDENCE',
                'automatic_mutation'=>false,
            ];
        }
        usort($rows,static fn(array $a,array $b):int=>strcmp($a['venue_symbol'],$b['venue_symbol']));
        return [
            'status'=>'READ_ONLY_REVIEW','provider'=>$scope[0],'venue_id'=>$scope[1],
            'required_consecutive'=>$requiredConsecutive,'rows'=>$rows,
            'executable'=>false,'auto_delist'=>false,'auto_mapping'=>false,
        ];
    }
}
