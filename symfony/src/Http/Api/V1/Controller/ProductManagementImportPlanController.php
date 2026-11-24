<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\ProductManagement\ProductImportPlanProposalService;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Authenticated human-manager entry point to the canonical Federation
 * Goal/Plan proposal. Does NOT approve a Plan or execute an Action.
 */
final readonly class ProductManagementImportPlanController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
        private ProductImportPlanProposalService $proposals,
    ) {}

    public function propose(Request $request,string $batchId):JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null||!$tenant->isManager()||!$tenant->allows(TenantPermissions::MANAGE)) {
            return $this->reply(403,'manager_required');
        }
        $org=$tenant->organizationId()->value();
        if(!$this->modules->isEnabled($org,'product_management')) {
            return $this->reply(409,'product_management_inactive');
        }
        if(!$this->csrf->isValid($request)) return $this->reply(400,'invalid_csrf_token');
        if(!preg_match('/^[a-f0-9]{32}$/D',$batchId)) return $this->reply(422,'invalid_batch_id');
        $raw=(string)$request->getContent();
        if(strlen($raw)>2048) return $this->reply(413,'input_too_large');
        $json=json_decode($raw);
        if(!$json instanceof \stdClass) return $this->reply(422,'invalid_payload');
        $data=json_decode($raw,true);
        $digest=$data['batch_digest']??null;
        $rowsDigest=$data['rows_digest']??null;
        if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)
            ||!is_string($rowsDigest)||!preg_match('/^[a-f0-9]{64}$/D',$rowsDigest)) {
            return $this->reply(422,'invalid_digests');
        }
        try {
            $result=$this->proposals->propose($tenant,$batchId,$digest,$rowsDigest);
            $response=new JsonResponse(['ok'=>true,'data'=>$result],201);
            return $this->private($response);
        } catch(DomainException $e) {
            return $this->reply(409,'immutable_preview_or_plan_conflict');
        } catch(Throwable $e) {
            error_log('product_management_import_plan_proposal_failed '.get_class($e));
            return $this->reply(500,'proposal_failed');
        }
    }

    private function reply(int $status,string $error):JsonResponse
    {
        return $this->private(new JsonResponse(['ok'=>false,'error'=>$error],$status));
    }

    private function private(JsonResponse $response):JsonResponse
    {
        $response->headers->set('Cache-Control','private, no-store');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
