<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPropertyInventoryCollectionQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class PropertyInventoryController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private PropertyInventoryPresenter $presenter,
    ) {
    }

    public function manage(Request $request): Response
    {
        $tenant=$this->manager();
        if($tenant instanceof Response)return $tenant;
        return $this->collection($request,$tenant,'manage');
    }

    public function listing(Request $request): Response
    {
        $tenant=$this->listingUser();
        if($tenant instanceof Response)return $tenant;
        return $this->collection($request,$tenant,'listing');
    }

    private function collection(Request $request,TenantContext $tenant,string $mode): Response
    {
        $gridQuery=$this->gridQuery($request);
        $activeItem=$mode==='listing'?'listing':'objects';
        $title=$mode==='listing'?'Property Listing':'Property Inventory';

        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),
            surface:'workspace',
            activeSection:'properties',
            activeItem:$activeItem,
        );
        $shell=$this->shells->create($tenant,$context,$title,[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Properties','/property/manage'),
            new ShellBreadcrumb($mode==='listing'?'Listing':'Inventory'),
        ]);

        try{
            $data=$this->queries->ask(new GetPropertyInventoryCollectionQuery(
                organizationId:$tenant->organizationId(),
                search:$gridQuery->search,
                filters:$gridQuery->filters,
                page:$gridQuery->page,
                perPage:$gridQuery->perPage,
            ));
            $inventory=$this->presenter->present(is_array($data)?$data:[],$gridQuery,$mode);

            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::Collection,$this->patterns(),$inventory->state()),
                'inventory'=>$inventory,
            ]);
        }catch(Throwable $error){
            error_log('property.inventory.read_failed '.$error->getMessage());
            $inventory=$this->presenter->present([],$gridQuery,$mode,'Property inventory тимчасово недоступний.');

            return $this->render([
                'shell'=>$shell,
                'page'=>$this->pages->create(PageArchetype::Collection,$this->patterns(),'error'),
                'inventory'=>$inventory,
            ],Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function gridQuery(Request $request): DataGridQuery
    {
        $input=$request->query->all();
        $filters=is_array($input['filter']??null)?$input['filter']:[];
        foreach(['status','transaction_type','type_code'] as $key){
            if(array_key_exists($key,$filters))continue;
            $value=trim((string)$request->query->get($key,''));
            if($value!=='')$filters[$key]=$value;
        }
        $input['filter']=$filters;
        return DataGridQuery::fromArray($input);
    }

    private function manager(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!$tenant->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function listingUser(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!in_array($tenant->role()->value(),['realtor','developer','partner','manager','admin'],true)){
            return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        }
        return $tenant;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return ['PageHeader','Toolbar','DataGrid','EmptyState','ErrorState'];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/property/inventory.html.twig',$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }
}
