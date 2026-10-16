<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Property\PropertyBulkIntakeService;
use App\Persistence\Property\PropertyBulkPlanProposalService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/** Human-session ingestion only. Execution remains with approved Kernel Action. */
final readonly class PropertyBulkIntakeController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private PropertyBulkIntakeService $intake,
        private PropertyBulkPlanProposalService $plans,
    ) {}

    public function stage(Request $request):JsonResponse
    {
        if (!$this->csrf->isValid($request)) return self::reply(['error'=>'csrf'],400);
        $json=$this->parse($request);
        if ($json===null || !isset($json['items']) || !is_array($json['items'])
            || !array_is_list($json['items'])) return self::reply(['error'=>'invalid_items'],422);
        try { return self::reply($this->intake->stage($this->actor(),$json['items']),201); }
        catch (DomainException|\InvalidArgumentException $e) {
            return self::reply(['error'=>'intake_blocked','reason'=>$e->getMessage()],422);
        } catch(Throwable $e) { error_log('cos.property.bulk.stage '.$e->getMessage());
            return self::reply(['error'=>'intake_unavailable'],500); }
    }

    public function show(string $batchId):JsonResponse
    {
        try { return self::reply($this->intake->preview($this->actor(),$batchId)); }
        catch (DomainException|\InvalidArgumentException) { return self::reply(['error'=>'not_found_or_forbidden'],404); }
        catch(Throwable $e) { error_log('cos.property.bulk.show '.$e->getMessage());
            return self::reply(['error'=>'intake_unavailable'],500); }
    }

    public function propose(Request $request,string $batchId):JsonResponse
    {
        if (!$this->csrf->isValid($request)) return self::reply(['error'=>'csrf'],400);
        $json=$this->parse($request);
        if (!is_array($json) || !is_string($json['digest']??null)) {
            return self::reply(['error'=>'review_digest_required'],422);
        }
        try { return self::reply($this->plans->propose($this->actor(),$batchId,$json['digest']),201); }
        catch (DomainException|\InvalidArgumentException $e) {
            return self::reply(['error'=>'plan_blocked','reason'=>$e->getMessage()],422);
        } catch(Throwable $e) { error_log('cos.property.bulk.propose '.$e->getMessage());
            return self::reply(['error'=>'plan_unavailable'],500); }
    }

    private function actor():TenantContext
    {
        return $this->tenants->current() ?? throw new DomainException('Missing tenant session.');
    }

    /** @return array<string,mixed>|null */
    private function parse(Request $request):?array
    {
        $raw=(string)$request->getContent();
        if (strlen($raw)>65536 || !json_validate($raw)) return null;
        $root=json_decode($raw);
        if (!$root instanceof \stdClass) return null;
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:null;
    }

    /** @param array<string,mixed> $data */
    private static function reply(array $data,int $status=200):JsonResponse
    {
        $res=new JsonResponse($data,$status);
        $res->headers->set('Cache-Control','private, no-store');
        return $res;
    }
}
