<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

use DomainException;

/**
 * Stable weighted rendezvous allocation of whole source leases across nodes.
 * Source cannot be split between owners. No balancing through LIVE order paths.
 * Costs are explicitly estimated milli-USD per hour, never invoices.
 */
final readonly class StreamingFleetPlacementPolicy
{
    /**
     * @param list<array<string,mixed>> $sources
     * @param list<string> $nodes
     * @return array<string,mixed>
     */
    public function place(array $sources,array $nodes,int $maxSourcesPerNode=16,
        int $maxSubscriptionsPerNode=1200,int $budgetMilliUsdHour=200000,
        int $baseCostMilliUsdHour=10,int $quoteCostMilliUsdHour=1):array
    {
        if(count($nodes)<1||count($nodes)>32||count(array_unique($nodes))!==count($nodes)
            ||count($sources)<1||count($sources)>64
            ||$maxSourcesPerNode<1||$maxSourcesPerNode>64
            ||$maxSubscriptionsPerNode<1||$maxSubscriptionsPerNode>6400
            ||$budgetMilliUsdHour<1||$budgetMilliUsdHour>100000000
            ||$baseCostMilliUsdHour<0||$quoteCostMilliUsdHour<0){
            throw new DomainException('Invalid fleet capacity/cost envelope.');
        }
        foreach($nodes as $node)if(!is_string($node)
            ||preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$/D',$node)!==1){
            throw new DomainException('Invalid approved fleet node ID.');
        }
        sort($nodes,SORT_STRING);
        $seen=[];$fingerprints=[];$ledger=[];
        foreach($nodes as $node)$ledger[$node]=['sources'=>[],'subscriptions'=>0,'estimated_milliusd_hour'=>0];
        usort($sources,static fn(array $a,array $b):int=>
            strcmp((string)($a['source_id']??''),(string)($b['source_id']??'')));
        foreach($sources as $row){
            $id=$row['source_id']??null;$count=$row['subscriptions']??null;
            if(!is_string($id)||$id===''||strlen($id)>190
                ||isset($seen[$id])||!is_int($count)||$count<1||$count>100){
                throw new DomainException('Unknown source or capacity exceeds supported worker limit.');
            }
            $seen[$id]=true;
            // Hash the fully resolved source topology (including ordered
            // canonical subscriptions), not only source IDs. Changed symbols
            // require a new explicit pin on every node before execute.
            $fingerprints[$id]=hash('sha256',json_encode($row,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $cost=$baseCostMilliUsdHour+$count*$quoteCostMilliUsdHour;
            // Consistent order; adding a new node does not depend on iteration timing.
            $order=$nodes;
            usort($order,static fn(string $a,string $b):int=>
                strcmp(hash('sha256',$id.'|'.$b),hash('sha256',$id.'|'.$a)));
            $placed=false;
            foreach($order as $node){
                $state=&$ledger[$node];
                if(count($state['sources'])<$maxSourcesPerNode
                    &&$state['subscriptions']+$count<=$maxSubscriptionsPerNode
                    &&$state['estimated_milliusd_hour']+$cost<=$budgetMilliUsdHour){
                    $state['sources'][]=$id;
                    $state['subscriptions']+=$count;
                    $state['estimated_milliusd_hour']+=$cost;
                    $placed=true;unset($state);break;
                }
                unset($state);
            }
            if(!$placed)throw new DomainException('No authorized fleet node has capacity/budget for source '.$id);
        }
        foreach($ledger as &$item)sort($item['sources'],SORT_STRING);
        unset($item);
        $digest=hash('sha256',json_encode([$nodes,$fingerprints,
            $maxSourcesPerNode,$maxSubscriptionsPerNode,
            $budgetMilliUsdHour,$baseCostMilliUsdHour,$quoteCostMilliUsdHour],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return ['status'=>'PLANNED_NOT_RUNNING','nodes'=>$ledger,
            'topology_digest'=>$digest,'cost_semantics'=>'ESTIMATE_NOT_INVOICE',
            'preserves_whole_source_fencing'=>true,'live_changes'=>false];
    }
}
