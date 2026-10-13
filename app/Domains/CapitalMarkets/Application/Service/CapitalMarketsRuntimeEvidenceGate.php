<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

/**
 * Deterministic safety gates for the agent workflow.
 * LLM confidence, proposed decisions and prompts never pass these checks.
 */
final class CapitalMarketsRuntimeEvidenceGate
{
    /** @param array<string,mixed> $market */
    public function observation(array $market):?string
    {
        $sources=$market['sources']??null;
        if(!is_array($sources)||$sources===[]){
            return 'NO_REGISTERED_MARKET_SOURCES';
        }
        foreach(['states','reference_states'] as $type){
            foreach($market[$type]??[] as $row){
                if(!is_array($row))continue;
                $quality=$row['quality']??null;
                // Venue MarketState exposes quality_status at the top level;
                // ReferenceMarketState exposes quality.status instead.
                $status=strtoupper((string)(
                    $row['quality_status']??(is_array($quality)?($quality['status']??''):'')
                ));
                $mode=strtoupper((string)($row['mode']??'LIVE'));
                $timestamp=$row['source_timestamp']??null;
                $epoch=is_string($timestamp)?strtotime($timestamp):false;
                if($epoch===false || time()-$epoch>120 || $epoch-time()>5)continue;
                if($status==='TRUSTED'&&$mode==='LIVE'){
                    return null;
                }
            }
        }
        return 'NO_TRUSTED_LIVE_MARKET_EVIDENCE';
    }

    /** @param array<string,mixed>|null $opportunity */
    public function opportunity(?array $opportunity):?string
    {
        if($opportunity===null||$opportunity===[]){
            return 'OPPORTUNITY_NOT_PERSISTED';
        }
        $status=strtoupper((string)($opportunity['status']??''));
        if(in_array($status,['REJECTED','EXPIRED','INVALIDATED','CANCELLED'],true)){
            return 'OPPORTUNITY_NOT_ELIGIBLE';
        }
        return null;
    }

    /** @param array<string,mixed> $portfolio */
    public function risk(array $portfolio):?string
    {
        // The deterministic capital/risk engine is the sole authority.
        if(empty($portfolio['risk'])||empty($portfolio['risk_envelope'])){
            return 'DETERMINISTIC_RISK_ENVELOPE_MISSING';
        }
        if(empty($portfolio['capital_state'])||empty($portfolio['allocation_plan'])){
            return 'DETERMINISTIC_ALLOCATION_PLAN_MISSING';
        }
        return null;
    }

    /** @param array<string,mixed>|null $execution */
    public function measuredExecution(?array $execution,string $opportunityId):?string
    {
        if($execution===null||$execution===[]){
            return 'PAPER_EXECUTION_PENDING_HUMAN_APPROVAL';
        }
        if((string)($execution['opportunity_id']??'')!==$opportunityId){
            return 'EXECUTION_OPPORTUNITY_MISMATCH';
        }
        if(!in_array(strtoupper((string)($execution['status']??'')),
            ['COMPLETED','SETTLED','FILLED'],true)){
            return 'PAPER_EXECUTION_NOT_FINALIZED';
        }
        return null;
    }
}
