<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Sales\Application\Contract\SalesOrderReportReadPort;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Finance\Application\Contract\CommerceOrderFinancialEvidenceReadPort;

/** Current state of a placed-order cohort; not an as-of history or recognized revenue. */
final readonly class SalesOrderReportService
{
    public function __construct(private SalesOrderReportReadPort $orders,private SalesWorkspaceScopeInterface $scope,
        private SalesMutationGuard $guard,private SalesMutationAuthorityInterface $authority,
        private ?CommerceOrderFinancialEvidenceReadPort $finance=null){}
    public function read(string $org,int $actor,array $input):array
    {
        if(array_diff(array_keys($input),['scope','owner_id','from','to'])!==[])throw new DomainException('Invalid order report filter.');
        $from=self::date($input['from']??null);$to=self::date($input['to']??null);
        if($from>=$to||$from->diff($to)->days>366)throw new DomainException('Invalid order report window.');
        $mode=$input['scope']??'self';$owner=$input['owner_id']??null;
        if(!in_array($mode,['self','owner','tenant'],true)|| (array_key_exists('owner_id',$input)&&(!is_int($owner)||$owner<1)))
            throw new DomainException('Invalid order report scope.');
        return $this->guard->run($org,$actor,function()use($org,$actor,$mode,$owner,$from,$to):array{
            $authorized=$this->scope->resolve($org,$actor,$mode,$owner);
            $rows=$this->orders->reportCandidates($org,$authorized,$from->format('Y-m-d'),$to->format('Y-m-d'));
            if(count($rows)>500)throw new DomainException('Order report exceeds cohort bound; narrow the dates.');
            $groups=[];
            foreach($rows as $r){
                // Re-attest linked deal ownership under the same native transaction.
                if($r['deal_id']!==null){
                    try{$this->authority->entity($org,$actor,'deal',(int)$r['deal_id']);}
                    catch(DomainException){continue;}
                }
                $c=$r['currency'];$status=$r['status'];$id=$r['order_id'];
                if(!in_array($c,['EUR','USD','UAH'],true)||!in_array($status,['placed','confirmed','cancelled','completed'],true))
                    throw new DomainException('Invalid native order report state.');
                $k=$c.'/'.$status;
                $groups[$k]??=['currency'=>$c,'status'=>$status,'order_count'=>0,'placed_minor'=>0,'financial_evidence_count'=>0,'net_minor'=>0];
                $groups[$k]['order_count']++;$groups[$k]['placed_minor']+=self::minor($r['total']);
                $f=$this->finance?->financialEvidence($org,$id);
                if($f===null)continue;
                if(($f['schema_version']??null)!=='1.0.0'||($f['organization_id']??null)!==$org||($f['order_id']??null)!==$id||
                    ($f['currency']??null)!==$c||!is_int($f['revision']??null)||$f['revision']<1||
                    !is_string($f['evidence_reference']??null)||!preg_match('/^[a-f0-9]{32}$/D',$f['evidence_reference']))
                    throw new DomainException('Order report Finance evidence mismatch.');
                $net=self::minor($f['net_collected_amount']??null);
                if(self::minor($f['captured_amount']??null)-self::minor($f['refunded_amount']??null)-self::minor($f['chargeback_amount']??null)!==$net)
                    throw new DomainException('Order report Finance evidence imbalance.');
                $groups[$k]['financial_evidence_count']++;$groups[$k]['net_minor']+=$net;
            }
            if($this->scope->resolve($org,$actor,$mode,$owner)!==$authorized)throw new DomainException('Order report scope changed.');
            ksort($groups,SORT_STRING);
            return ['basis'=>'placed_cohort_current_state','from'=>$from->format('Y-m-d'),'to_exclusive'=>$to->format('Y-m-d'),
                'owner_id'=>$authorized,'pipeline_id'=>null,'items'=>array_values($groups)];
        });
    }
    private static function date(mixed $s):DateTimeImmutable
    {
        if(!is_string($s)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$s))throw new DomainException('Invalid order report date.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d')!==$s)throw new DomainException('Invalid order report date.');
        return $d;
    }
    private static function minor(mixed $s):int
    {
        if(!is_string($s)||!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$s))throw new DomainException('Exact order amount required.');
        return (int)str_replace('.','',$s);
    }
    public static function decimal(int $minor):string
    {return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
}
