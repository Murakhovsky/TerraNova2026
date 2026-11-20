<?php
declare(strict_types=1);
namespace App\Web\Experience\Unified;

use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Extension\EntityLinkResolver;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Security\SessionCsrfValidator;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;
use Throwable;

/**
 * UX-03. No Domain hardcode or data repository. WorkspaceDefinition,
 * EntityRef and existing read-only Web providers are authoritative.
 */
final readonly class UnifiedBusinessWorkspaceController {
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private WebExtensionCatalog $extensions,
        private WorkspaceCompositionResolver $composition,
        private EntityLinkResolver $links,
        private WorkspaceShellFactory $shells,
        private SessionCsrfValidator $csrf,
        private Environment $twig,
    ) {}
    public function show(Request $request,string $workspaceId): Response {
        $tenant=$this->tenants->current();
        if ($tenant===null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager() || !$tenant->allows(TenantPermissions::ACCESS)
            || !$tenant->allows(TenantPermissions::MANAGE)) return new Response('Forbidden',403);

        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),surface:'workspace',
            activeSection:'workspaces',activeItem:'unified',
        );
        try {
            $workspace=$this->composition->resolve($tenant,$context,$workspaceId);
        } catch (InvalidArgumentException|LogicException) {
            return new Response('Unknown or unavailable business context.',404);
        }
        $catalog=$this->extensions->forContext($context);
        $query=trim((string)$request->query->get('q',''));
        if (mb_strlen($query)>120) $query=mb_substr($query,0,120);
        $view=(string)$request->query->get('view','list');
        if (!in_array($view,['list','cards'],true)) $view='list';
        $sort=(string)$request->query->get('sort','relevance');
        if (!in_array($sort,['relevance','label'],true)) $sort='relevance';
        $selectedKey=trim((string)$request->query->get('selected',''));
        if (strlen($selectedKey)>191) $selectedKey='';
        $results=[];
        $searchAvailable=true;
        $selected=null;
        $selectedHit=null;
        $entityType=$workspace->definition->entityType;
        if ($query!=='') {
            // Global aggregation already enforces active modules and tenant scope.
            // Only SearchResult entities for the registered Workspace are allowed.
            try {
                $hits=$catalog->search($query,50);
            } catch (Throwable $e) {
                $searchAvailable=false;
                $hits=[];
                error_log('cos.unified.business.search_unavailable '.$e->getMessage());
            }
            foreach ($hits as $hit) {
                if (!$hit instanceof SearchResult || $hit->entity===null
                    || $hit->entity->type!==$entityType
                    || !str_starts_with($hit->path,'/')
                    || str_starts_with($hit->path,'//')) continue;
                $results[]=$hit;
            }
            if ($sort==='label') {
                usort($results,static fn(SearchResult $a,SearchResult $b):int
                    => strcmp(mb_strtolower($a->label),mb_strtolower($b->label)));
            }
            foreach ($results as $hit) {
                if ($selectedKey!=='' && $hit->entity->key()===$selectedKey) {
                    $selected=$hit->entity;
                    $selectedHit=$hit;
                    break;
                }
            }
        }
        // A browser-supplied EntityRef never enters Workspace composition
        // without positive tenant-bound Query readback from the same provider.
        if ($selected instanceof EntityRef) {
            $workspace=$this->composition->resolve($tenant,$context,$workspaceId,$selected);
        }

        $selectedUrl=null;
        if ($selected instanceof EntityRef && $selectedHit instanceof SearchResult) {
            try {
                $canonical=$this->links->resolve($context,$selected);
                $candidate=$canonical?->path ?? $selectedHit->path;
                if (str_starts_with($candidate,'/') && !str_starts_with($candidate,'//')
                    && preg_match('/[\\x00-\\x1F\\x7F]/',$candidate)!==1) {
                    $selectedUrl=$candidate;
                }
            } catch (LogicException) {
                // Ambiguous links are never fabricated or followed.
            }
        }
        $activity=[];
        $activityAvailable=true;
        try {
            $activity=$catalog->activity(10);
        } catch (Throwable $e) {
            $activityAvailable=false;
            error_log('cos.unified.business.activity_unavailable '.$e->getMessage());
        }
        $savedViews=$this->loadSavedViews($request,$tenant,$workspaceId);
        $shell=$this->shells->create($tenant,$context,$workspace->definition->label,[
            new ShellBreadcrumb('Робочі простори','/workspace/unified'),
            new ShellBreadcrumb($workspace->definition->label),
        ],aiAvailable:false,connectionState:ShellConnectionState::Stale);
        return new Response($this->twig->render('experience/unified/business.html.twig',[
            'shell'=>$shell,'workspace'=>$workspace,'workspaces'=>$catalog->workspaces(),
            'query'=>$query,'view'=>$view,'sort'=>$sort,'results'=>$results,
            'searchAvailable'=>$searchAvailable,
            'selected'=>$selected,'selectedHit'=>$selectedHit,'selectedUrl'=>$selectedUrl,
            'activity'=>$activity,'activityAvailable'=>$activityAvailable,
            'selectionDenied'=>$selectedKey!=='' && $selected===null,
            'savedViews'=>$savedViews,'csrfToken'=>$this->csrf->token($request),
        ]),200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
    /**
     * UI-only filter preferences. Never creates a Domain Action or writes
     * business records. Bound to the server session + actor + organization.
     */
    public function saveView(Request $request,string $workspaceId): Response {
        $tenant=$this->tenants->current();
        if ($tenant===null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager() || !$tenant->allows(TenantPermissions::ACCESS)
            || !$tenant->allows(TenantPermissions::MANAGE)) return new Response('Forbidden',403);
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF.',403);
        if (!$request->hasSession()) return new Response('Session unavailable.',400);
        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),surface:'workspace',
            activeSection:'workspaces',activeItem:'unified',
        );
        try {
            $this->composition->resolve($tenant,$context,$workspaceId);
        } catch (InvalidArgumentException|LogicException) {
            return new Response('Unknown or unavailable workspace.',404);
        }
        $session=$request->getSession();
        $all=$session->get('cos_uw_saved_views_v1',[]);
        if (!is_array($all)) $all=[];
        $bucket=$this->savedViewBucket($tenant,$workspaceId);
        $views=is_array($all[$bucket]??null) ? $all[$bucket] : [];
        $action=(string)$request->request->get('operation','save');
        if ($action==='delete') {
            $id=(string)$request->request->get('view_id','');
            if (!preg_match('/^[a-f0-9]{16}$/D',$id)) return new Response('Invalid view reference.',422);
            unset($views[$id]);
        } elseif ($action==='save') {
            $name=trim((string)$request->request->get('name',''));
            $query=trim((string)$request->request->get('q',''));
            $sort=(string)$request->request->get('sort','relevance');
            $view=(string)$request->request->get('view','list');
            if ($name==='' || mb_strlen($name)>50 || $query==='' || mb_strlen($query)>120
                || !in_array($sort,['relevance','label'],true)
                || !in_array($view,['list','cards'],true)) {
                return new Response('Invalid saved view input.',422);
            }
            $id=substr(hash('sha256',mb_strtolower($name)),0,16);
            if (!isset($views[$id]) && count($views)>=10) {
                return new Response('Saved view limit reached.',422);
            }
            $views[$id]=['id'=>$id,'name'=>$name,'q'=>$query,'sort'=>$sort,'view'=>$view];
        } else {
            return new Response('Unknown preference operation.',422);
        }
        $all[$bucket]=$views;
        $session->set('cos_uw_saved_views_v1',$all);
        return new RedirectResponse(
            '/workspace/unified/business/'.rawurlencode($workspaceId).'?saved=1',
            Response::HTTP_SEE_OTHER,
        );
    }

    /** @return list<array{id:string,name:string,q:string,sort:string,view:string}> */
    private function loadSavedViews(Request $request,\Kernel\Tenant\Model\TenantContext $tenant,string $workspaceId): array {
        if (!$request->hasSession()) return [];
        $all=$request->getSession()->get('cos_uw_saved_views_v1',[]);
        if (!is_array($all)) return [];
        $items=$all[$this->savedViewBucket($tenant,$workspaceId)]??[];
        return is_array($items) ? array_values(array_filter($items,'is_array')) : [];
    }

    private function savedViewBucket(\Kernel\Tenant\Model\TenantContext $tenant,string $workspaceId): string {
        return hash('sha256',$tenant->organizationId()->value().'|'
            .$tenant->userId()->value().'|'.$workspaceId);
    }

}
