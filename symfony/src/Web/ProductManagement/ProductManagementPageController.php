<?php
declare(strict_types=1);

namespace App\Web\ProductManagement;

use App\Application\Capability\CanonicalCapabilityGateway;
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

/**
 * E5 human-only catalog UX. All reads/writes pass the canonical Capability
 * Gateway and Domain buses; this controller has no database/writer access.
 */
final readonly class ProductManagementPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CanonicalCapabilityGateway $gateway,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
    ) {}

    public function index(Request $request): Response
    {
        $actor=$this->manager();
        if ($actor instanceof Response) return $actor;
        $q=trim((string)$request->query->get('q',''));
        if (mb_strlen($q)>200) return new Response('Search query too long.',422);
        $page=filter_var($request->query->get('page','1'),FILTER_VALIDATE_INT);
        if ($page===false||$page<1||$page>1000000) return new Response('Invalid page.',422);
        $limit=30;
        try {
            $data=$this->read($actor,'product.search',['q'=>$q,'limit'=>$limit,'offset'=>($page-1)*$limit]);
            return $this->render($request,$actor,'catalog',[
                'rows'=>$data['items']??[], 'q'=>$q, 'pageNumber'=>$page,
                'hasNext'=>count($data['items']??[])===$limit,
            ]);
        } catch (Throwable $e) {
            error_log('product_management.workspace.catalog_unavailable '.$e::class);
            return new Response('Product catalog temporarily unavailable.',503);
        }
    }

    public function product(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if ($actor instanceof Response) return $actor;
        try {
            $product=$this->readProduct($actor,$id);
            if ($product===null) return new Response('Product not found.',404);
            $categories=$this->read($actor,'category.search',['limit'=>100,'offset'=>0]);
            $prices=[];
            foreach (array_slice($product['variants']??[],0,30) as $variant) {
                $vid=(string)($variant['variant_id']??'');
                if (preg_match('/^[a-f0-9]{32}$/D',$vid)) {
                    $price=$this->read($actor,'price.base.get',['variant_id'=>$vid,'currency'=>'UAH']);
                    $prices[$vid]=$price['price']??null;
                }
            }
            return $this->render($request,$actor,'product',[
                'product'=>$product,'categories'=>$categories['items']??[], 'prices'=>$prices,
            ]);
        } catch (Throwable $e) {
            error_log('product_management.workspace.detail_unavailable '.$e::class);
            return new Response('Product details temporarily unavailable.',503);
        }
    }

    public function categories(Request $request):Response
    {
        $actor=$this->manager();
        if ($actor instanceof Response) return $actor;
        try {
            $data=$this->read($actor,'category.search',['limit'=>100,'offset'=>0]);
            return $this->render($request,$actor,'categories',['categories'=>$data['items']??[]]);
        } catch (Throwable $e) {
            error_log('product_management.workspace.categories_unavailable '.$e::class);
            return new Response('Product categories temporarily unavailable.',503);
        }
    }

    public function create(Request $request):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $type=trim((string)$request->request->get('type','stock'));
        $input=[
            'name'=>trim((string)$request->request->get('name','')),
            'sku'=>trim((string)$request->request->get('sku','')),
            'type'=>$type,
            'uom'=>trim((string)$request->request->get('uom','pcs')),
            'track_inventory'=>in_array($type,['stock','material'],true)
                &&$request->request->get('track_inventory','')==='1',
        ];
        try {
            $result=$this->write($actor,'product.create',$input,$request);
            $id=(string)($result['product_id']??'');
            if (!preg_match('/^[a-f0-9]{32}$/D',$id) || $this->readProduct($actor,$id)===null) {
                throw new DomainException('Product create receipt could not be verified.');
            }
            return $this->go('/product-management/products/'.$id.'?notice=created');
        } catch (DomainException|\InvalidArgumentException $e) {
            return new Response('Product creation blocked. Verify SKU, type and required fields.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.create_failed '.$e::class);
            return new Response('Product result unverified; inspect catalog before retrying.',503);
        }
    }

    public function update(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $input=[
            'product_id'=>$id,
            'expected_revision'=>(int)$request->request->get('expected_revision',0),
            'name'=>trim((string)$request->request->get('name','')),
            'brand'=>trim((string)$request->request->get('brand','')),
            'description'=>trim((string)$request->request->get('description','')),
        ];
        return $this->mutateProduct($actor,$request,$id,'product.update',$input);
    }

    public function variant(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $existing=$this->checkedProduct($actor,$id);
        if ($existing instanceof Response) return $existing;
        $type=(string)($existing['product_type']??'stock');
        $input=[
            'product_id'=>$id,
            'sku'=>trim((string)$request->request->get('sku','')),
            'uom'=>trim((string)$request->request->get('uom','pcs')),
            'track_inventory'=>in_array($type,['stock','material'],true),
        ];
        return $this->mutateProduct($actor,$request,$id,'variant.create',$input);
    }

    public function translate(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $input=[
            'product_id'=>$id,
            'locale'=>trim((string)$request->request->get('locale','uk')),
            'name'=>trim((string)$request->request->get('name','')),
            'description'=>trim((string)$request->request->get('description','')),
        ];
        return $this->mutateProduct($actor,$request,$id,'translation.upsert',$input);
    }

    public function assignCategory(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        return $this->mutateProduct($actor,$request,$id,'category.assign',[
            'product_id'=>$id,'category_id'=>trim((string)$request->request->get('category_id','')),
        ]);
    }

    public function setPrice(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $product=$this->checkedProduct($actor,$id);
        if ($product instanceof Response) return $product;
        $variantId=trim((string)$request->request->get('variant_id',''));
        $ownsVariant=false;
        foreach ($product['variants']??[] as $variant) {
            if (($variant['variant_id']??null)===$variantId && ($variant['status']??null)==='active') {
                $ownsVariant=true;
                break;
            }
        }
        if (!$ownsVariant) return new Response('Variant does not belong to this active Product.',422);
        $input=[
            'variant_id'=>$variantId,
            'currency'=>'UAH',
            'amount'=>trim((string)$request->request->get('amount','')),
        ];
        return $this->mutateProduct($actor,$request,$id,'price.base.set',$input);
    }

    public function createCategory(Request $request):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $input=[
            'name'=>trim((string)$request->request->get('name','')),
            'slug'=>trim((string)$request->request->get('slug','')),
        ];
        $parent=trim((string)$request->request->get('parent_id',''));
        if ($parent!=='') $input['parent_id']=$parent;
        try {
            $this->write($actor,'category.create',$input,$request);
            return $this->go('/product-management/categories?notice=created');
        } catch (DomainException|\InvalidArgumentException) {
            return new Response('Category creation blocked. Check name, slug and parent.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.category_failed '.$e::class);
            return new Response('Category result unverified. Inspect catalog before retrying.',503);
        }
    }

    /** @param array<string,mixed> $input */
    private function mutateProduct(
        TenantContext $actor,Request $request,string $id,string $capability,array $input
    ):Response {
        $current=$this->checkedProduct($actor,$id);
        if ($current instanceof Response) return $current;
        if (($current['status']??null)==='archived') return new Response('Archived Product cannot be edited.',422);
        try {
            $this->write($actor,$capability,$input,$request);
            $updated=$this->readProduct($actor,$id);
            if ($updated===null) throw new DomainException('Product mutation has no independently readable result.');
            return $this->go('/product-management/products/'.$id.'?notice=updated');
        } catch (DomainException|\InvalidArgumentException) {
            return new Response('Product action blocked. Check fields, revision and current ownership.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.mutation_failed '.$e::class);
            return new Response('Product result unverified. Reload before retrying.',503);
        }
    }

    private function checkedProduct(TenantContext $actor,string $id):array|Response
    {
        try {
            $product=$this->readProduct($actor,$id);
            return $product??new Response('Product not found.',404);
        } catch (Throwable $e) {
            error_log('product_management.workspace.product_lookup_failed '.$e::class);
            return new Response('Product temporarily unavailable.',503);
        }
    }

    /** @return array<string,mixed>|null */
    private function readProduct(TenantContext $actor,string $id):?array
    {
        $value=$this->read($actor,'product.get',['product_id'=>$id]);
        if (($value['found']??false)!==true||!is_array($value['product']??null)) return null;
        return $value['product'];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function read(TenantContext $actor,string $capability,array $input):array
    {
        $result=$this->gateway->invoke($actor,'product_management.'.$capability,$input,
            'trusted_read','',CorrelationId::generate()->value());
        if (!is_array($result)) throw new DomainException('Product query returned an invalid result.');
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function write(TenantContext $actor,string $capability,array $input,Request $request):array
    {
        $key=trim((string)$request->request->get('idempotency_key',''));
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new DomainException('Invalid operation receipt key.');
        $result=$this->gateway->invoke($actor,'product_management.'.$capability,$input,
            'human_ui','pm-ui-'.$key,CorrelationId::generate()->value());
        if (!is_array($result)) throw new DomainException('Product command produced no receipt.');
        return $result;
    }

    private function guardWrite(Request $request):TenantContext|Response
    {
        $actor=$this->manager();
        if ($actor instanceof Response) return $actor;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        return $actor;
    }

    private function manager():TenantContext|Response
    {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)
            ||!$actor->allows(TenantPermissions::MANAGE)) return new Response('Forbidden',403);
        if (!$this->modules->isEnabled($actor->organizationId()->value(),'product_management'))
            return new Response('Product Management module is disabled for this organization.',403);
        return $actor;
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request,TenantContext $actor,string $section,array $data):Response
    {
        $label=match($section){
            'catalog'=>'Каталог товарів','product'=>'Картка товару','categories'=>'Категорії',
            default=>'Product Management',
        };
        $context=new WebExtensionContext(
            $actor->organizationId()->value(),$actor->role()->value(),
            'workspace','product-management','pm-'.$section
        );
        $shell=$this->shells->create($actor,$context,$label,[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Product Management','/product-management'),
            new ShellBreadcrumb($label),
        ]);
        return new Response($this->twig->render('experience/product_management/workspace.html.twig',$data+[
            'shell'=>$shell,'section'=>$section,'pageTitle'=>$label,
            'csrfToken'=>$this->csrf->token($request),
            'mutationKey'=>bin2hex(random_bytes(16)),
            'notice'=>(string)$request->query->get('notice',''),
        ]),200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private',
            'X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }

    private function go(string $path):RedirectResponse
    {
        return new RedirectResponse($path,303);
    }
}
