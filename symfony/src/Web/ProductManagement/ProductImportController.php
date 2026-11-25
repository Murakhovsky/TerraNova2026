<?php
declare(strict_types=1);

namespace App\Web\ProductManagement;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Persistence\ProductManagement\ProductImportPlanProposalService;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/** Governed CSV Preview and Plan proposal, never an import executor. */
final readonly class ProductImportController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CanonicalCapabilityGateway $gateway,
        private ProductImportPlanProposalService $plans,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
    ) {}

    public function index(Request $request):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        return $this->render($request,$actor,'preview',[]);
    }

    public function batch(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        try{
            $report=$this->report($actor,$id);
            return $this->render($request,$actor,'batch',['batch'=>$report]);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('CSV import batch not found in tenant.',404);
        }catch(Throwable $e){
            error_log('product_management.csv.batch_read_failed '.$e::class);
            return new Response('CSV batch evidence temporarily unavailable.',503);
        }
    }

    public function preview(Request $request):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        $csv=$request->request->get('csv');
        if(!is_string($csv)||$csv===''||strlen($csv)>12000)
            return new Response('CSV must be non-empty and at most 12000 bytes.',422);
        try{
            $key=$this->key($request);
            $receipt=$this->gateway->invoke($actor,'product_management.import.preview',[
                'csv'=>$csv,
            ],'human_ui','pm-csv-ui-'.$key,CorrelationId::generate()->value());
            if(!is_array($receipt)||!preg_match('/^[a-f0-9]{32}$/D',(string)($receipt['batch_id']??'')))
                throw new DomainException('CSV preview receipt is invalid.');
            $report=$this->report($actor,(string)$receipt['batch_id']);
            if(($report['batch_id']??null)!==$receipt['batch_id']
                ||!hash_equals(hash('sha256',$csv),(string)($report['digest']??''))
                ||!hash_equals((string)($receipt['rows_digest']??''),(string)($report['rows_digest']??''))){
                throw new DomainException('CSV immutable preview evidence mismatch.');
            }
            return new RedirectResponse('/product-management/import/'.$receipt['batch_id'],303);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('CSV preview blocked. Verify header, size and valid rows.',422);
        }catch(Throwable $e){
            error_log('product_management.csv.preview_failed '.$e::class);
            return new Response('CSV Preview result uncertain. Inspect batch before retrying.',503);
        }
    }

    public function propose(Request $request,string $id):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        try{
            $report=$this->report($actor,$id);
            if(($report['state']??null)!=='staged'||($report['plan_id']??null)!==null
                ||count($report['rows']??[])<1||($report['counts']['ready']??0)!==count($report['rows']??[])){
                throw new DomainException('Only fully ready, unclaimed CSV can propose a Plan.');
            }
            $digest=trim((string)$request->request->get('digest',''));
            $rowsDigest=trim((string)$request->request->get('rows_digest',''));
            if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||!preg_match('/^[a-f0-9]{64}$/D',$rowsDigest)
                ||!hash_equals((string)$report['digest'],$digest)
                ||!hash_equals((string)$report['rows_digest'],$rowsDigest)){
                throw new DomainException('CSV review digest mismatch.');
            }
            $receipt=$this->plans->propose($actor,$id,$digest,$rowsDigest);
            $after=$this->report($actor,$id);
            if(($after['goal_id']??null)!==($receipt['goal_id']??null)
                ||($after['plan_id']??null)!==($receipt['plan_id']??null)){
                throw new DomainException('Proposed Plan not independently visible on CSV batch.');
            }
            return new RedirectResponse('/product-management/import/'.$id.'?notice=proposed',303);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('CSV Plan proposal blocked. All rows must be ready; approval is independent.',422);
        }catch(Throwable $e){
            error_log('product_management.csv.plan_proposal_failed '.$e::class);
            return new Response('Plan outcome uncertain. Verify batch and Goal before retrying.',503);
        }
    }

    /** @return array<string,mixed> */
    private function report(TenantContext $actor,string $batch):array
    {
        $result=$this->gateway->invoke($actor,'product_management.import.batch.get',
            ['batch_id'=>$batch],'trusted_read','',CorrelationId::generate()->value());
        if(!is_array($result)||($result['batch_id']??null)!==$batch)
            throw new DomainException('CSV report scope mismatch.');
        return $result;
    }

    private function key(Request $request):string
    {
        $key=trim((string)$request->request->get('idempotency_key',''));
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Invalid CSV Preview idempotency key.');
        return $key;
    }

    private function writeGuard(Request $request):TenantContext|Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',403);
        return $actor;
    }

    private function manager():TenantContext|Response
    {
        $actor=$this->tenants->current();
        if($actor===null)return new RedirectResponse('/auth/login');
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE))
            return new Response('Forbidden.',403);
        if(!$this->modules->isEnabled($actor->organizationId()->value(),'product_management'))
            return new Response('Product Management disabled for tenant.',403);
        return $actor;
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request,TenantContext $actor,string $section,array $data):Response
    {
        $context=new WebExtensionContext(
            $actor->organizationId()->value(),$actor->role()->value(),
            'workspace','product-management','pm-import'
        );
        $shell=$this->shells->create($actor,$context,'CSV-імпорт товарів',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Product Management','/product-management'),
            new ShellBreadcrumb('CSV-імпорт'),
        ]);
        return new Response($this->twig->render('experience/product_management/import.html.twig',$data+[
            'shell'=>$shell,'section'=>$section,
            'csrfToken'=>$this->csrf->token($request),
            'mutationKey'=>bin2hex(random_bytes(16)),
            'notice'=>(string)$request->query->get('notice',''),
        ]),200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private',
            'X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
}
