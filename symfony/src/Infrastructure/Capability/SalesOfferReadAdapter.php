<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesOfferRepositoryInterface;
use Domains\Sales\Application\Support\SalesOfferSnapshot;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesOfferReadAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesOfferRepositoryInterface $offers){}
    public function binding():string { return 'query:sales.offer.read'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['offer_id']??null;$org=$actor->organizationId()->value();
        if(count($input)!==1||!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1)throw new DomainException('Invalid offer identity.');
        $row=$this->offers->get($org,$id);
        if($row===null)return ['found'=>false,'offer'=>null,'history'=>[],'terms_history'=>[]];
        $state=SalesOfferSnapshot::fromNative($row,$org,$id);
        $history=[];
        foreach($this->offers->history($org,$id) as $h)$history[]=[
            'revision'=>(int)$h['revision'],'terms_version'=>(int)$h['terms_version'],'kind'=>$h['kind'],'from_status'=>$h['from_status'],'to_status'=>$h['to_status'],
            'has_evidence'=>$h['evidence_reference']!==null,'note'=>$h['note'],'actor_id'=>(int)$h['actor_id'],'created_at'=>$h['created_at'],
        ];
        $terms=[];
        foreach($this->offers->termsHistory($org,$id) as $v)$terms[]=[
            'terms_version'=>(int)$v['terms_version'],'title'=>$v['title'],'total'=>(string)$v['total'],'currency'=>$v['currency'],'valid_until'=>$v['valid_until'],
            'lines'=>json_decode($v['lines_json'],true,flags:JSON_THROW_ON_ERROR),'direction'=>$v['direction'],'terms_hash'=>$v['terms_hash'],
        ];
        return ['found'=>true,'offer'=>$state,'history'=>$history,'terms_history'=>$terms];
    }
}
