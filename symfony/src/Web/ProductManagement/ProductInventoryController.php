<?php
declare(strict_types=1);

namespace App\Web\ProductManagement;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Persistence\ProductManagement\ProductStockCountPlanProposalService;
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

/** Human-only inventory operator. All writes remain native Domain commands. */
final readonly class ProductInventoryController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CanonicalCapabilityGateway $gateway,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private ProductStockCountPlanProposalService $countPlans,
    ) {}

    public function index(Request $request):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        $variant=trim((string)$request->query->get('variant',''));
        $location=trim((string)$request->query->get('location',''));
        if(($variant!==''&&!preg_match('/^[a-f0-9]{32}$/D',$variant))
            ||($location!==''&&!preg_match('/^[a-f0-9]{32}$/D',$location))){
            return new Response('Invalid stock selection.',422);
        }
        try{
            $loc=$this->read($actor,'location.search',['limit'=>100,'offset'=>0]);
            $products=$this->read($actor,'product.search',['limit'=>100,'offset'=>0]);
            $balance=null;$movements=[];$reservations=[];
            $reservationPage=filter_var($request->query->get('reservation_page','1'),FILTER_VALIDATE_INT);
            if($reservationPage===false||$reservationPage<1||$reservationPage>333334)return new Response('Invalid reservation page.',422);
            if($variant!==''){
                $moves=$this->read($actor,'stock.movement.search',[
                    'variant_id'=>$variant,'limit'=>30,'offset'=>0
                ]);
                $movements=$moves['items']??[];
                if($location!==''){
                    $balance=$this->read($actor,'stock.balance.get',[
                        'variant_id'=>$variant,'location_id'=>$location
                    ]);
                    $reservations=$this->read($actor,'stock.reservation.search',[
                        'variant_id'=>$variant,'location_id'=>$location,'limit'=>30,'offset'=>($reservationPage-1)*30,
                    ])['items']??[];
                }
            }
            $countId=trim((string)$request->query->get('count_id',''));
            if($countId!==''&&!preg_match('/^[a-f0-9]{32}$/D',$countId))return new Response('Invalid count identity.',422);
            $count=$countId===''?null:($this->read($actor,'stock.count.get',['count_id'=>$countId])['count']??null);
            if($count!==null&&($count['variant_id']!==$variant||$count['location_id']!==$location))return new Response('Count scope mismatch.',404);
            $context=new WebExtensionContext(
                $actor->organizationId()->value(),$actor->role()->value(),
                'workspace','product-management','pm-stock'
            );
            $shell=$this->shells->create($actor,$context,'Складський облік',[
                new ShellBreadcrumb('Workspace','/admin'),
                new ShellBreadcrumb('Product Management','/product-management'),
                new ShellBreadcrumb('Складський облік'),
            ]);
            return new Response($this->twig->render('experience/product_management/stock.html.twig',[
                'shell'=>$shell,'locations'=>$loc['items']??[], 'catalog'=>$products['items']??[],
                'variantId'=>$variant,'locationId'=>$location,
                'countId'=>$countId,'count'=>$count,'balance'=>$balance,'movements'=>$movements,'reservations'=>$reservations,'reservationPage'=>$reservationPage,
                'csrfToken'=>$this->csrf->token($request),
                'mutationKey'=>bin2hex(random_bytes(16)),
                'notice'=>(string)$request->query->get('notice',''),
            ]),200,[
                'Content-Type'=>'text/html; charset=UTF-8',
                'Cache-Control'=>'no-store, private',
                'X-Robots-Tag'=>'noindex, nofollow',
            ]);
        }catch(Throwable $e){
            error_log('product_management.stock_workspace.unavailable '.$e::class);
            return new Response('Native stock information unavailable. No external stock fallback.',503);
        }
    }

    public function createLocation(Request $request):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        $code=trim((string)$request->request->get('code',''));
        try{
            $result=$this->write($actor,'location.create',[
                'code'=>$code,'name'=>trim((string)$request->request->get('name','')),
            ],$request);
            $id=(string)($result['location_id']??'');
            $list=$this->read($actor,'location.search',['q'=>$code,'limit'=>100,'offset'=>0]);
            $found=false;
            foreach($list['items']??[] as $loc){
                if(($loc['location_id']??null)===$id&&($loc['code']??null)===$code)$found=true;
            }
            if(!$found)throw new DomainException('Warehouse receipt could not be verified.');
            return new RedirectResponse('/product-management/stock?notice=created',303);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Warehouse creation blocked. Check code and name.',422);
        }catch(Throwable $e){
            error_log('product_management.stock_workspace.location_failed '.$e::class);
            return new Response('Warehouse result unverified. Inspect before retrying.',503);
        }
    }

    public function post(Request $request):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        $kind=trim((string)$request->request->get('kind',''));
        $variant=trim((string)$request->request->get('variant_id',''));
        $source=trim((string)$request->request->get('source_ref',''));
        $quantity=trim((string)$request->request->get('quantity',''));
        $from=trim((string)$request->request->get('from_location_id',''));
        $to=trim((string)$request->request->get('to_location_id',''));
        if(!in_array($kind,['receive','issue','transfer'],true))return new Response('Unsupported stock operation.',422);
        $input=['variant_id'=>$variant,'quantity'=>$quantity,'source_ref'=>$source];
        if($kind==='transfer'){
            $input['from_location_id']=$from;
            $input['to_location_id']=$to;
        }else{
            $input['location_id']=$from;
        }
        try{
            $operation=match($kind){
                'receive'=>'stock.receive',
                'issue'=>'stock.issue',
                'transfer'=>'stock.transfer',
                default=>throw new DomainException('Unsupported stock operation.'),
            };
            $result=$this->write($actor,$operation,$input,$request);
            if($kind==='transfer'){
                foreach([['from_balance',$from],['to_balance',$to]] as [$field,$loc]){
                    $after=$this->read($actor,'stock.balance.get',[
                        'variant_id'=>$variant,'location_id'=>$loc
                    ]);
                    $this->assertBalanceMatches($after,$result[$field]??null,$variant,$loc);
                }
                if(($result['from_location_id']??null)!==$from
                    ||($result['to_location_id']??null)!==$to
                    ||!preg_match('/^[a-f0-9]{32}$/D',(string)($result['out_movement_id']??''))
                    ||!preg_match('/^[a-f0-9]{32}$/D',(string)($result['in_movement_id']??''))){
                    throw new DomainException('Transfer receipt is incomplete.');
                }
            }else{
                $after=$this->read($actor,'stock.balance.get',[
                    'variant_id'=>$variant,'location_id'=>$from
                ]);
                $this->assertBalanceMatches($after,$result['balance']??null,$variant,$from);
                if(!preg_match('/^[a-f0-9]{32}$/D',(string)($result['movement_id']??''))){
                    throw new DomainException('Movement receipt is incomplete.');
                }
            }
            return new RedirectResponse('/product-management/stock?variant='.rawurlencode($variant).
                '&location='.rawurlencode($kind==='transfer'?$to:$from).'&notice=posted',303);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Stock operation blocked. Verify available quantity, location, source document and permissions.',422);
        }catch(Throwable $e){
            error_log('product_management.stock_workspace.post_failed '.$e::class);
            return new Response('Stock result uncertain. Reload native balance before retrying.',503);
        }
    }

    public function proposeCount(Request $request):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        $variant=(string)$request->request->get('variant_id','');
        $location=(string)$request->request->get('location_id','');
        $revision=filter_var($request->request->get('expected_revision'),FILTER_VALIDATE_INT);
        if($revision===false||$revision<0)return new Response('Invalid count revision.',422);
        try{
            $proposal=$this->countPlans->propose($actor,$variant,$location,
                trim((string)$request->request->get('counted','')),$revision,
                trim((string)$request->request->get('source_ref','')),(string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/product-management/stock?variant='.$variant.'&location='.$location.'&count_id='.$proposal['count_id'].'&notice=count_proposed',303);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Count proposal blocked. Reload stock and verify document.',422);
        }catch(Throwable $e){
            error_log('product_management.stock_workspace.count_proposal_uncertain '.$e::class);
            return new Response('Count proposal uncertain. Inspect Goal Workspace before retrying.',503);
        }
    }

    public function reservation(Request $request):Response
    {
        $actor=$this->writeGuard($request);
        if($actor instanceof Response)return $actor;
        $action=(string)$request->request->get('action','');
        $variant=(string)$request->request->get('variant_id','');
        $location=(string)$request->request->get('location_id','');
        if(!in_array($action,['reserve','release'],true)||!preg_match('/^[a-f0-9]{32}$/D',$variant)||!preg_match('/^[a-f0-9]{32}$/D',$location))
            return new Response('Invalid reservation request.',422);
        $scope=['variant_id'=>$variant,'location_id'=>$location];
        $reservationId=(string)$request->request->get('reservation_id','');
        if($action==='release'){
            if(!preg_match('/^[a-f0-9]{32}$/D',$reservationId)||$request->request->get('confirm_release')!=='yes')
                return new Response('Reservation and release confirmation required.',422);
            try{
                $before=$this->read($actor,'stock.reservation.search',$scope+['reservation_id'=>$reservationId,'limit'=>1])['items'][0]??null;
                if($before===null)return new Response('Reservation not found in selected scope.',404);
            }catch(Throwable $e){return new Response('Reservation unavailable.',503);}
            $input=['reservation_id'=>$reservationId,'reason'=>trim((string)$request->request->get('reason',''))];
        }else{
            $input=$scope+['quantity'=>trim((string)$request->request->get('quantity','')),
                'source_ref'=>trim((string)$request->request->get('source_ref',''))];
        }
        try{
            $receipt=$this->write($actor,'stock.'.$action,$input,$request);
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Reservation action blocked. Check quantity, origin and permissions.',422);
        }catch(Throwable $e){
            return new Response('Reservation outcome uncertain. Reload before retrying.',503);
        }
        try{
            $id=(string)($receipt['reservation_id']??'');
            if(!preg_match('/^[a-f0-9]{32}$/D',$id)||($action==='release'&&$id!==$reservationId))
                throw new \RuntimeException('Reservation receipt identity mismatch.');
            $row=$this->read($actor,'stock.reservation.search',$scope+['reservation_id'=>$id,'limit'=>1])['items'][0]??[];
            $expected=$action==='reserve'?'active':'released';
            foreach(['reservation_id'=>$id,'variant_id'=>$variant,'location_id'=>$location,'status'=>$expected] as $field=>$value){
                if(($row[$field]??null)!==$value||($receipt[$field]??null)!==$value)throw new \RuntimeException('Reservation readback mismatch.');
            }
            if($action==='reserve'){
                [$whole,$fraction]=array_pad(explode('.',$input['quantity'],2),2,'');
                if(($row['quantity']??null)!==$whole.'.'.str_pad($fraction,6,'0')||($row['source_ref']??null)!==$input['source_ref'])
                    throw new \RuntimeException('Reservation quantity/origin mismatch.');
            }elseif(($row['release_reason']??null)!==$input['reason']){
                throw new \RuntimeException('Release reason mismatch.');
            }
            $balance=$this->read($actor,'stock.balance.get',$scope);
            $this->assertBalanceMatches($balance,$receipt['balance']??null,$variant,$location);
            return new RedirectResponse('/product-management/stock?variant='.$variant.'&location='.$location.'&notice=posted',303);
        }catch(Throwable $e){
            error_log('product_management.stock_workspace.reservation_readback_uncertain '.$e::class);
            return new Response('Reservation receipt obtained; state unverified. Reload before retrying.',503);
        }
    }

    /** @param array<string,mixed> $fresh */
    private function assertBalanceMatches(array $fresh,mixed $receipt,string $variant,string $loc):void
    {
        if(!is_array($receipt)||($fresh['variant_id']??null)!==$variant
            ||($fresh['location_id']??null)!==$loc) {
            throw new DomainException('Stock balance identity mismatch.');
        }
        foreach(['on_hand','reserved','available'] as $field){
            if(!isset($receipt[$field],$fresh[$field])
                ||(string)$receipt[$field] !== (string)$fresh[$field]){
                throw new DomainException('Stock balance changed or receipt does not match native read.');
            }
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function read(TenantContext $actor,string $operation,array $input):array
    {
        $value=$this->gateway->invoke($actor,'product_management.'.$operation,
            $input,'trusted_read','',CorrelationId::generate()->value());
        if(!is_array($value))throw new DomainException('Native Product query returned non-object.');
        return $value;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function write(TenantContext $actor,string $operation,array $input,Request $request):array
    {
        $key=trim((string)$request->request->get('idempotency_key',''));
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Invalid operation receipt key.');
        $value=$this->gateway->invoke($actor,'product_management.'.$operation,
            $input,'human_ui','pm-stock-'.$key,CorrelationId::generate()->value());
        if(!is_array($value))throw new DomainException('Native stock command has no receipt.');
        return $value;
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
            ||!$actor->allows(TenantPermissions::MANAGE))return new Response('Forbidden.',403);
        if(!$this->modules->isEnabled($actor->organizationId()->value(),'product_management'))
            return new Response('Product Management disabled for tenant.',403);
        return $actor;
    }
}
