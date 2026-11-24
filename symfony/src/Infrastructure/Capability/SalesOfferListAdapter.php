<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Contract\SalesOfferRepositoryInterface;
use Domains\Sales\Application\Support\SalesOfferSnapshot;
use DomainException;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesOfferListAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesOfferRepositoryInterface $offers){}
    public function binding():string { return 'query:sales.offer.list'; }
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $deal=$input['deal_id']??null;$limit=$input['limit']??50;$org=$actor->organizationId()->value();
        if(array_diff(array_keys($input),['deal_id','limit'])!==[]||!is_int($deal)||$deal<1||!is_int($limit)||$limit<1||$limit>100)throw new DomainException('Invalid offer list bounds.');
        $items=[];foreach($this->offers->forDeal($org,$deal,$limit) as $row){
            if((int)$row['deal_id']!==$deal)throw new DomainException('Offer deal mismatch.');
            $items[]=SalesOfferSnapshot::fromNative($row,$org,$row['offer_id']);
        }
        return ['items'=>$items];
    }
}
