<?php
declare(strict_types=1);
namespace App\Web\Experience\Unified;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\AdaptivePagePresentation;
use App\Web\Experience\Adaptive\ExperienceMode;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use DomainException;
use InvalidArgumentException;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;
use Twig\Environment;

/** UX-02: only existing typed Gateway + existing human Command form. */
final readonly class UnifiedCapabilityController {
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private CanonicalCapabilityGateway $gateway,
        private UnifiedCapabilityPresentation $presenter,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private Environment $twig,
        private AdaptivePagePresentation $adaptive,
    ) {}
    public function index(Request $request): Response {
        $actor=$this->actor();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$this->canManage($actor)) return new Response('Forbidden',403);
        try {
            $inventory=$this->gateway->inventory($actor);
        } catch (DomainException) {
            return new Response('Forbidden',403);
        }
        $operations=[];
        foreach ($inventory as $entry) {
            if (!is_array($entry) || !is_array($entry['contract']??null)) continue;
            // A typed contract alone must never become an actionable/dummy
            // card. Expose only explicitly designed and domain-owned pilots.
            if (!$this->presenter->supports((string)($entry['id']??''))) continue;
            $operations[]=$this->presenter->describe($entry);
        }
        $triggers=[];
        $createAvailable=false;
        foreach ($operations as $op) {
            if ($op['needs_approval']) $triggers[]='approval_required';
            if (!$op['ui_ready']) $triggers[]='permission_unavailable';
            if ($op['id']==='property.asset.create' && $op['ui_ready'] && $op['status']==='direct_human') {
                $createAvailable=true;
            }
        }
        // Notice is only a legacy display hint, never authoritative success/permission.
        $adaptive=$this->adaptive->prepare($request,$actor,'unified.actions',[
            AdaptivePagePresentation::section('actions.details',ExperienceMode::Expert),
        ],array_values(array_unique($triggers)),operationClass:'action');
        if ($adaptive instanceof Response) return $adaptive;
        return $this->render($actor,'experience/unified/capabilities.html.twig',[
            'adaptive'=>$adaptive,'operations'=>$operations,'createAvailable'=>$createAvailable,
            'csrfToken'=>$this->csrf->token($request),
            'idempotencyKey'=>bin2hex(random_bytes(16)),
            'notice'=>in_array((string)$request->query->get('notice',''),
                ['invalid','unverified','unavailable'],true)
                ? (string)$request->query->get('notice','') : '',
        ],'Операції бізнесу');
    }
    public function property(Request $request): Response {
        $actor=$this->actor();
        if ($actor===null) return new RedirectResponse('/auth/login');
        if (!$this->canManage($actor)) return new Response('Forbidden',403);
        $reference=trim((string)$request->query->get('reference',''));
        $query=trim((string)$request->query->get('q',''));
        if (mb_strlen($query)>120) $query=mb_substr($query,0,120);
        $page=max(1,min(10000,(int)$request->query->get('page',1)));
        $perPage=20;
        $error=null;
        $result=null;
        if ($reference!=='' && preg_match('/^PROP-[A-F0-9]{20}$/D',$reference)!==1) {
            $error='Некоректний ідентифікатор об’єкта.';
        } elseif ($reference!=='' || $query!=='') {
            try {
                $id=$reference!=='' ? 'property.asset.get' : 'property.asset.search';
                $input=$reference!=='' ? ['reference'=>$reference]
                    : ['q'=>$query,'page'=>$page,'per_page'=>$perPage];
                $result=$this->gateway->invoke(
                    $actor,$id,$input,'trusted_read','',CorrelationId::generate()->value(),
                );
            } catch (DomainException|InvalidArgumentException $e) {
                $error='Запит недоступний або не дозволений у цьому контексті.';
            } catch (Throwable $e) {
                error_log('cos.unified.property.query_failed '.$e->getMessage());
                $error='Не вдалося підтвердити дані. Повторіть лише читання.';
            }
        }
        $property=is_array($result) && is_array($result['property']??null) ? $result['property'] : null;
        $sections=$property!==null ? [AdaptivePagePresentation::section('property.identifier',ExperienceMode::Expert)] : [];
        $triggers=$error!==null ? ['failed'] : [];
        if ($property!==null) $triggers[]='result_available';
        $adaptive=$this->adaptive->prepare($request,$actor,'unified.property',$sections,$triggers,
            entity: $property!==null ? (string)($property['asset_id']??'') : '');
        if ($adaptive instanceof Response) return $adaptive;
        return $this->render($actor,'experience/unified/property_query.html.twig',[
            'adaptive'=>$adaptive,'query'=>$query,'reference'=>$reference,
            'page'=>$page,'perPage'=>$perPage,'data'=>is_array($result)?$result:null,
            'error'=>$error,
        ],'Пошук нерухомості');
    }
    private function actor(): ?TenantContext {return $this->tenants->current();}
    private function canManage(TenantContext $actor):bool {
        return $actor->isManager() && $actor->allows(TenantPermissions::ACCESS)
            && $actor->allows(TenantPermissions::MANAGE);
    }
    /** @param array<string,mixed> $vars */
    private function render(TenantContext $actor,string $view,array $vars,string $title):Response {
        $context=new WebExtensionContext(
            organizationId:$actor->organizationId()->value(),
            role:$actor->role()->value(),surface:'workspace',
            activeSection:'workspaces',activeItem:'unified',
        );
        $vars['shell']=$this->shells->create(
            $actor,$context,$title,
            [new ShellBreadcrumb('Робочі простори','/workspace/unified'),new ShellBreadcrumb($title)],
            aiAvailable:false,
            connectionState:\App\Web\Experience\Shell\ShellConnectionState::Stale,
        );
        return new Response($this->twig->render($view,$vars),200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
}
