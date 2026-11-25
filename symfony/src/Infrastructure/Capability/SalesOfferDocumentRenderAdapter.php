<?php
declare(strict_types=1);
namespace App\Infrastructure\Capability;
use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use Domains\Sales\Application\Service\SalesOfferDeliveryService;
use Kernel\Tenant\Model\TenantContext;
use DomainException;
final readonly class SalesOfferDocumentRenderAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private SalesOfferDeliveryService $delivery){}
    public function binding():string{return 'query:sales.offer.document.render';}
    public function invoke(TenantContext $actor,array $input,string $key,string $correlationId):mixed
    {
        $id=$input['offer_id']??null;
        if(count($input)!==1||!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1)throw new DomainException('Invalid offer identity.');
        return $this->delivery->document($actor->organizationId()->value(),$id);
    }
}
