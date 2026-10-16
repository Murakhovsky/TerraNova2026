<?php
declare(strict_types=1);
namespace App\Http\Api\V1\Controller;
use App\Application\Capability\CanonicalCapabilityGateway;
use App\Security\SessionCsrfValidator;
use DomainException;
use InvalidArgumentException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class CanonicalCapabilityController {
    public function __construct(
        private CanonicalCapabilityGateway $capabilities,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
    ) {}
    public function index():JsonResponse {
        try { return $this->reply(['capabilities'=>$this->capabilities->inventory($this->actor())]); }
        catch (DomainException $e) { return $this->reply(['error'=>'capability_forbidden'],403); }
    }
    public function show(string $id):JsonResponse {
        try { return $this->reply($this->capabilities->describe($this->actor(),$id)); }
        catch (DomainException $e) { return $this->reply(['error'=>'capability_unavailable'],403); }
    }
    public function invoke(Request $request,string $id):JsonResponse {
        if (!$this->csrf->isValid($request)) return $this->reply(['error'=>'invalid_csrf_token'],400);
        $raw=(string)$request->getContent();
        if (strlen($raw)>16384) return $this->reply(['error'=>'input_too_large'],413);
        // Preserve the distinction between an empty JSON object and an array.
        $root=json_decode($raw);
        if (!$root instanceof \stdClass) return $this->reply(['error'=>'invalid_json_object'],422);
        $input=json_decode($raw,true);
        if (!is_array($input)) return $this->reply(['error'=>'invalid_json_object'],422);
        try {
            $trace=$request->attributes->get('_cos_correlation_id');
            $correlation=$trace instanceof CorrelationId?$trace->value():CorrelationId::generate()->value();
            $result=$this->capabilities->invoke(
                $this->actor(),$id,$input,'human_api',
                trim((string)$request->headers->get('X-Idempotency-Key','')),$correlation,
            );
            return $this->reply(['ok'=>true,'capability_id'=>$id,'data'=>$result]);
        } catch (DomainException|InvalidArgumentException $e) {
            return $this->reply(['error'=>'invocation_blocked','reason'=>$e->getMessage()],422);
        } catch (Throwable $e) {
            error_log('cos.capability.invocation_failed '.$e->getMessage());
            return $this->reply(['error'=>'invocation_failed'],500);
        }
    }
    private function actor():TenantContext {
        return $this->tenants->current()??throw new DomainException('Missing session tenant.');
    }
    /** @param array<string,mixed> $payload */
    private function reply(array $payload,int $status=200):JsonResponse {
        $r=new JsonResponse($payload,$status);
        $r->headers->set('Cache-Control','no-store, private');
        $r->headers->set('X-Robots-Tag','noindex, nofollow');
        return $r;
    }
}
