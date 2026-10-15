<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scheduled desired BBO reconciliation via existing audited subscription
 * boundary. Does NOT start a second WebSocket transport or approve listings.
 */
final readonly class BStocksPoolSubscriptionReconciler
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private CrossVenueSubscriptionPlanner $tokens,
        private BStocksReferenceBboPlanner $references,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string,mixed> */
    public function reconcile(string $org,int $actor,int $limitPerSource=100):array
    {
        if ($org===''||strlen($org)>190||$actor<1||$limitPerSource<1||$limitPerSource>100) {
            throw new InvalidArgumentException('Tenant, actor and bounded source capacity required.');
        }
        $allow=['binance.spot.rest'=>true,'bybit.spot.v5'=>true,
            'kraken.spot.rest'=>true,'massive.stocks.v2'=>true];
        $sources=array_values(array_filter($this->sources->list($org,true),
            static fn($source):bool=>isset($allow[$source->adapterType])));
        if (count($sources)>4) {
            return ['status'=>'SOURCE_CAP_EXCEEDED','sources'=>count($sources),'rows'=>[]];
        }
        $rank=['massive.stocks.v2'=>0,'binance.spot.rest'=>1,'bybit.spot.v5'=>2,'kraken.spot.rest'=>3];
        usort($sources,static fn($a,$b):int=>($rank[$a->adapterType]??99)<=>($rank[$b->adapterType]??99));
        $remaining=250;
        $out=[];
        foreach ($sources as $source) {
            $id=$source->id->value();
            try {
                $reference=$source->adapterType==='massive.stocks.v2';
                $preview=$reference
                    ? $this->references->plan($org,$id,$limitPerSource)
                    : $this->tokens->plan($org,$id,$limitPerSource);
                $admitted=(int)$preview['existing']+(int)$preview['create'];
                if($admitted>$remaining){
                    $out[]=['source'=>$id,'status'=>'BLOCKED_GLOBAL_BBO_BUDGET',
                        'requested'=>$admitted,'remaining'=>$remaining];
                    continue;
                }
                $result=$reference
                    ? $this->references->apply($org,$actor,$id,$limitPerSource)
                    : $this->tokens->apply($org,$actor,$id,$limitPerSource);
                $remaining-=$admitted;
                $out[]=['source'=>$id,'status'=>'RECONCILED','created'=>$result['created']];
            } catch (Throwable $e) {
                $this->logger->warning('BStocks subscription source not admitted.',[
                    'organization_id'=>$org,'source_id'=>$id,'exception'=>get_class($e),
                ]);
                $out[]=['source'=>$id,'status'=>'BLOCKED','error_type'=>get_class($e)];
            }
        }
        return ['status'=>'COMPLETED_WITH_SOURCE_GATES',
            'streaming_confirmed'=>false,'sources'=>count($sources),
            'remaining_bbo_budget'=>$remaining,'rows'=>$out];
    }
}
