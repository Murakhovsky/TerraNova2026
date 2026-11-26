<?php
declare(strict_types=1);
namespace App\Web\ProductManagement;

use App\Persistence\ProductManagement\ProductGovernedPlanProposalService;
use App\Persistence\ProductManagement\ShopifyDraftPlanProposalService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** Authenticated proposal admission, never an agent approval or execution endpoint. */
final readonly class ProductGovernedPlanController
{
    public function __construct(private TenantContextProviderInterface $tenants,private SessionCsrfValidator $csrf,private ProductGovernedPlanProposalService $plans,private ShopifyDraftPlanProposalService $shopify){}
    public function propose(Request $request):JsonResponse
    {
        $actor=$this->tenants->current();
        if($actor===null||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
            return $this->reply(['error'=>'forbidden'],403);
        if(!$this->csrf->isValid($request))return $this->reply(['error'=>'csrf'],400);
        try{
            $raw=$request->getContent();
            if(strlen($raw)>66000)throw new DomainException('Proposal too large.');
            $p=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_diff(array_keys($p),['key','description','operations','csrf_token'])!==[]
                ||!is_string($p['key']??null)||!is_string($p['description']??null)||!is_array($p['operations']??null))
                throw new DomainException('Invalid proposal envelope.');
            return $this->reply($this->plans->propose($actor,$p['key'],$p['description'],$p['operations']),201);
        }catch(DomainException|\JsonException|\InvalidArgumentException){return $this->reply(['error'=>'invalid_or_conflicting_product_proposal'],422);}
        catch(Throwable){return $this->reply(['error'=>'proposal_unavailable_or_existing'],409);}
    }
    public function proposeShopifyDraft(Request $request):JsonResponse
    {
        $actor=$this->tenants->current();
        if($actor===null||!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
            return $this->reply(['error'=>'forbidden'],403);
        if(!$this->csrf->isValid($request))return $this->reply(['error'=>'csrf'],400);
        try{
            $raw=$request->getContent();
            if(strlen($raw)>4096)throw new DomainException('Proposal too large.');
            $p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_diff(array_keys($p),['product_id','connection_id','payload_digest','collection_gids','publication_gid','resync_id','csrf_token'])!==[]
                ||!is_string($p['product_id']??null)||!is_string($p['connection_id']??null)||!is_string($p['payload_digest']??null)||(isset($p['collection_gids'])&&!is_array($p['collection_gids']))||(isset($p['publication_gid'])&&!is_string($p['publication_gid']))||(isset($p['resync_id'])&&!is_string($p['resync_id'])))
                throw new DomainException('Invalid proposal.');
            return $this->reply($this->shopify->propose($actor,$p['connection_id'],$p['product_id'],$p['payload_digest'],$p['collection_gids']??[],$p['publication_gid']??null,$p['resync_id']??null),201);
        }catch(DomainException|\JsonException|\InvalidArgumentException){return $this->reply(['error'=>'invalid_or_conflicting_shopify_proposal'],422);}
        catch(Throwable){return $this->reply(['error'=>'proposal_unavailable_or_existing'],409);}
    }

    private function reply(array $data,int $status):JsonResponse
    {
        return new JsonResponse($data,$status,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}
