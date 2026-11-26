<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Capability\CanonicalAutomationIntentStore;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** Human tenant-wide Sales review API. No approval, worker or new UI. */
final readonly class SalesAutomationIntentReviewController
{
    public function __construct(private CanonicalAutomationIntentStore $intents,
        private TenantContextProviderInterface $tenants,private SessionCsrfValidator $csrf){}

    public function index():JsonResponse
    {
        try{
            $actor=$this->tenants->current()??throw new DomainException('Authentication required.');
            return $this->reply(['intents'=>$this->intents->pendingSales($actor)],200);
        }catch(DomainException){return $this->reply(['error'=>'forbidden'],403);}
        catch(Throwable){return $this->reply(['error'=>'review_unavailable'],503);}
    }

    public function inspect(string $intentId):JsonResponse
    {
        try{
            $actor=$this->tenants->current()??throw new DomainException('Authentication required.');
            return $this->reply($this->intents->inspectSales($actor,$intentId),200);
        }catch(DomainException){return $this->reply(['error'=>'forbidden_or_missing'],403);}
        catch(Throwable){return $this->reply(['error'=>'review_unavailable'],503);}
    }

    public function adopt(Request $request,string $intentId):JsonResponse
    {
        if(!$this->csrf->isValid($request))return $this->reply(['error'=>'csrf'],400);
        try{
            $actor=$this->tenants->current()??throw new DomainException('Authentication required.');
            $p=json_decode((string)$request->getContent(),true,8,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_diff(array_keys($p),['digest','csrf_token'])!==[]
                ||!is_string($p['digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['digest']))
                throw new DomainException('Reviewed digest required.');
            return $this->reply($this->intents->adoptSales($actor,$intentId,$p['digest']),201);
        }catch(DomainException|\JsonException|\InvalidArgumentException){return $this->reply(['error'=>'review_blocked'],422);}
        catch(Throwable){return $this->reply(['error'=>'review_unavailable'],503);}
    }

    private function reply(array $data,int $status):JsonResponse
    {
        return new JsonResponse($data,$status,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}
