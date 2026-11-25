<?php
declare(strict_types=1);
namespace App\Web\ProductManagement;

use App\Persistence\ProductManagement\ProductGovernedPlanProposalService;
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
    public function __construct(private TenantContextProviderInterface $tenants,private SessionCsrfValidator $csrf,private ProductGovernedPlanProposalService $plans){}
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
    private function reply(array $data,int $status):JsonResponse
    {
        return new JsonResponse($data,$status,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}
