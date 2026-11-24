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
 * E7/P0 human-only catalog UX. All reads/writes pass the canonical Capability
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
            $currency=strtoupper(trim((string)$request->query->get('currency','UAH')));
            if (!preg_match('/^[A-Z]{3}$/D',$currency)) return new Response('Invalid currency code.',422);
            $pricePage=filter_var($request->query->get('price_page','1'),FILTER_VALIDATE_INT);
            if ($pricePage===false || $pricePage<1 || $pricePage>1000000) return new Response('Invalid price page.',422);
            $priceVariants=array_slice($product['variants']??[],($pricePage-1)*30,30);
            $prices=[];
            foreach ($priceVariants as $variant) {
                $vid=(string)($variant['variant_id']??'');
                if (preg_match('/^[a-f0-9]{32}$/D',$vid)) {
                    $price=$this->read($actor,'price.base.get',['variant_id'=>$vid,'currency'=>$currency]);
                    $prices[$vid]=$price;
                }
            }
            return $this->render($request,$actor,'product',[
                'product'=>$product,'categories'=>$categories['items']??[], 'prices'=>$prices,
                'priceCurrency'=>$currency,'pricePage'=>$pricePage,
                'priceHasNext'=>count($product['variants']??[])>$pricePage*30,
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

    public function updateVariant(Request $request,string $id,string $variantId):Response
    {
        return $this->lifecycle($request,$id,$variantId,'variant.update');
    }

    public function archiveVariant(Request $request,string $id,string $variantId):Response
    {
        return $this->lifecycle($request,$id,$variantId,'variant.archive');
    }

    public function archiveProduct(Request $request,string $id):Response
    {
        return $this->lifecycle($request,$id,null,'product.archive');
    }

    private function lifecycle(Request $request,string $id,?string $variantId,string $capability):Response
    {
        $actor=$this->guardWrite($request);
        if ($actor instanceof Response) return $actor;
        $product=$this->checkedProduct($actor,$id);
        if ($product instanceof Response) return $product;
        if ($variantId!==null && $this->findVariant($product,$variantId)===null)
            return new Response('Variant not found for this Product.',404);
        $revision=filter_var($request->request->get('expected_revision'),FILTER_VALIDATE_INT);
        if ($revision===false || $revision<1) return new Response('Invalid revision.',422);
        $input=['expected_revision'=>$revision];
        $field=$variantId===null?'product_id':'variant_id';
        $target=$variantId??$id;
        $input[$field]=$target;
        if ($capability==='variant.update') {
            $input['sku']=strtoupper(trim((string)$request->request->get('sku','')));
            $input['uom']=trim((string)$request->request->get('uom',''));
            $input['barcode']=trim((string)$request->request->get('barcode',''));
        } elseif ($request->request->get('confirm_archive','')!=='1') {
            return new Response('Explicit archive confirmation required.',422);
        }
        try {
            $receipt=$this->write($actor,$capability,$input,$request);
        } catch (DomainException|\InvalidArgumentException) {
            return new Response('Action blocked. Check revision, SKU, stock, reservations and ownership.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.lifecycle_write_uncertain '.$e::class);
            return new Response('Operation outcome uncertain. Reload before retrying.',503);
        }
        // A receipt does not establish success without a separate scoped read.
        try {
            $after=$this->readProduct($actor,$id);
            $entity=$after===null?null:($variantId===null?$after:$this->findVariant($after,$variantId));
            $status=$capability==='variant.update'?'active':'archived';
            $verified=$entity!==null && ($receipt[$field]??null)===$target
                && ($receipt['status']??null)===$status
                && (int)($receipt['revision']??0)===$revision+1
                && ($entity[$field]??null)===$target && ($entity['status']??null)===$status
                && (int)($entity['revision']??0)===$revision+1;
            if ($capability==='variant.update' && $verified) {
                $verified=($entity['sku']??null)===$input['sku']
                    && ($entity['uom']??null)===$input['uom']
                    && (string)($entity['barcode']??'')===$input['barcode'];
            }
            if (!$verified) throw new \RuntimeException('Lifecycle readback mismatch.');
            return $this->go('/product-management/products/'.$id.'?notice=updated');
        } catch (Throwable $e) {
            error_log('product_management.workspace.lifecycle_readback_uncertain '.$e::class);
            return new Response('Operation receipt obtained; current state unverified. Reload before retrying.',503);
        }
    }

    private function findVariant(array $product,string $variantId):?array
    {
        foreach ($product['variants']??[] as $variant) {
            if (is_array($variant)&&($variant['variant_id']??null)===$variantId) return $variant;
        }
        return null;
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
        $currency=strtoupper(trim((string)$request->request->get('currency','')));
        $revision=filter_var($request->request->get('expected_revision'),FILTER_VALIDATE_INT);
        if (!preg_match('/^[A-Z]{3}$/D',$currency)||$revision===false||$revision<0)
            return new Response('Invalid currency or price revision.',422);
        if (($product['status']??null)==='archived') return new Response('Archived Product cannot be priced.',422);
        $input=[
            'variant_id'=>$variantId,'currency'=>$currency,
            'amount'=>trim((string)$request->request->get('amount','')),
            'expected_revision'=>$revision,
        ];
        try {
            $receipt=$this->write($actor,'price.base.set',$input,$request);
        } catch (DomainException|\InvalidArgumentException) {
            return new Response('Price action blocked. Reload current price and revision.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.price_write_uncertain '.$e::class);
            return new Response('Price outcome uncertain. Reload before retrying.',503);
        }
        try {
            if (!$this->verifyPrice($actor,$input,$receipt)) throw new \RuntimeException('Price readback mismatch.');
            return $this->go('/product-management/products/'.$id.'?currency='.$currency.'&notice=updated');
        } catch (Throwable $e) {
            error_log('product_management.workspace.price_readback_uncertain '.$e::class);
            return new Response('Price receipt obtained; current state unverified. Reload before retrying.',503);
        }
    }

    public function attachMedia(Request $request,string $id):Response
    {
        $actor=$this->guardWrite($request);
        if($actor instanceof Response)return $actor;
        $product=$this->checkedProduct($actor,$id);
        if($product instanceof Response)return $product;
        if(($product['status']??null)==='archived')
            return new Response('Archived product cannot acquire media.',422);
        $variantId=trim((string)$request->request->get('variant_id',''));
        $input=[
            'product_id'=>$id,
            'file_id'=>trim((string)$request->request->get('file_id','')),
            'role'=>trim((string)$request->request->get('role','gallery')),
            'sort_order'=>(int)$request->request->get('sort_order',100),
        ];
        if($variantId!==''){
            $owned=false;
            foreach($product['variants']??[] as $v){
                if(($v['variant_id']??null)===$variantId &&($v['status']??null)==='active'){
                    $owned=true;break;
                }
            }
            if(!$owned)return new Response('Media Variant is not an active part of this Product.',422);
            $input['variant_id']=$variantId;
        }
        try {
            $receipt=$this->write($actor,'media.attach',$input,$request);
            $mediaId=(string)($receipt['media_id']??'');
            $after=$this->read($actor,'media.search',['product_id'=>$id,'limit'=>100,'offset'=>0]);
            if(!$this->containsId($after['items']??[],'media_id',$mediaId))
                throw new DomainException('Media attachment not independently visible.');
            return $this->go('/product-management/products/'.$id.'?notice=updated');
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Media attachment blocked. Select a verified tenant Document image.',422);
        }catch(Throwable $e){
            error_log('product_management.workspace.media_attach_failed '.$e::class);
            return new Response('Media attachment result uncertain. Reload before retrying.',503);
        }
    }

    public function detachMedia(Request $request,string $id,string $mediaId):Response
    {
        $actor=$this->guardWrite($request);
        if($actor instanceof Response)return $actor;
        $product=$this->checkedProduct($actor,$id);
        if($product instanceof Response)return $product;
        if(($product['status']??null)==='archived')return new Response('Archived Product is immutable.',422);
        if(!$this->containsId($product['media']??[],'media_id',$mediaId))
            return new Response('Media reference not found for this tenant Product.',404);
        try {
            $receipt=$this->write($actor,'media.detach',['media_id'=>$mediaId],$request);
            if(($receipt['media_id']??null)!==$mediaId||($receipt['product_id']??null)!==$id)
                throw new DomainException('Media detached from an unexpected Product.');
            $after=$this->read($actor,'media.search',['product_id'=>$id,'limit'=>100,'offset'=>0]);
            if($this->containsId($after['items']??[],'media_id',$mediaId))
                throw new DomainException('Detached Product Media reference remains active.');
            return $this->go('/product-management/products/'.$id.'?notice=updated');
        }catch(DomainException|\InvalidArgumentException){
            return new Response('Media detach blocked. Verify current Product state.',422);
        }catch(Throwable $e){
            error_log('product_management.workspace.media_detach_failed '.$e::class);
            return new Response('Media detach outcome uncertain. Inspect current Product.',503);
        }
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
            $receipt=$this->write($actor,$capability,$input,$request);
            $updated=$this->readProduct($actor,$id);
            if ($updated===null) throw new DomainException('Product mutation has no independently readable result.');
            $this->verifyProductMutation($actor,$capability,$input,$current,$updated,$receipt);
            return $this->go('/product-management/products/'.$id.'?notice=updated');
        } catch (DomainException|\InvalidArgumentException) {
            return new Response('Product action blocked. Check fields, revision and current ownership.',422);
        } catch (Throwable $e) {
            error_log('product_management.workspace.mutation_failed '.$e::class);
            return new Response('Product result unverified. Reload before retrying.',503);
        }
    }

    /**
     * A native operation receipt alone is not UI success. Compare independent
     * post-command Domain query evidence (same tenant and exact entity).
     * @param array<string,mixed> $input
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $receipt
     */
    private function verifyProductMutation(
        TenantContext $actor,string $capability,array $input,array $before,array $after,array $receipt
    ):void {
        $verified=match($capability) {
            'product.update' => (int)($after['revision']??0)>=(int)$input['expected_revision']+1
                &&($after['name']??null)===$input['name']
                &&($after['brand']??null)===$input['brand']
                &&($after['description']??null)===$input['description'],
            'variant.create' => $this->containsId($after['variants']??[],'variant_id',(string)($receipt['variant_id']??'')),
            'translation.upsert' => $this->containsPair($after['translations']??[],'locale','name',
                (string)$input['locale'],(string)$input['name']),
            'category.assign' => $this->containsId($after['categories']??[],'category_id',(string)$input['category_id']),
            default => false,
        };
        if (!$verified) throw new DomainException('Canonical Domain result did not match independently readable state.');
    }

    /** @param array<int,mixed> $rows */
    private function containsId(array $rows,string $field,string $expected):bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$expected)) return false;
        foreach ($rows as $row) {
            if (is_array($row)&&($row[$field]??null)===$expected) return true;
        }
        return false;
    }

    /** @param array<int,mixed> $rows */
    private function containsPair(array $rows,string $a,string $b,string $x,string $y):bool
    {
        foreach ($rows as $row) {
            if (is_array($row)&&($row[$a]??null)===$x&&($row[$b]??null)===$y) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $input */
    private function verifyPrice(TenantContext $actor,array $input,array $receipt):bool
    {
        $price=$this->read($actor,'price.base.get',[
            'variant_id'=>(string)$input['variant_id'],'currency'=>(string)$input['currency'],
        ]);
        $actual=$price['price']??null;
        // Decimal comparison is exact to the Domain base-price scale (4),
        // without floating point rounding of potentially large money values.
        if (!is_string($actual)||!preg_match('/^([0-9]+)(?:\\.([0-9]{1,4}))?$/D',$actual)) return false;
        $expected=(string)$input['amount'];
        if (!preg_match('/^([0-9]+)(?:\\.([0-9]{1,4}))?$/D',$expected)) return false;
        $normalize=static function(string $n):string {
            [$whole,$fraction]=array_pad(explode('.',$n,2),2,'');
            $trimmed=ltrim($whole,'0');
            return ($trimmed===''?'0':$trimmed).'.'.str_pad($fraction,4,'0');
        };
        $revision=$input['expected_revision']+1;
        return $normalize($actual)===$normalize($expected)
            && ($receipt['variant_id']??null)===$input['variant_id']
            && ($price['variant_id']??null)===$input['variant_id']
            && ($receipt['currency']??null)===$input['currency']
            && ($price['currency']??null)===$input['currency']
            && ($receipt['price']??null)===$actual
            && (int)($receipt['revision']??0)===$revision
            && (int)($price['revision']??0)===$revision;
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
