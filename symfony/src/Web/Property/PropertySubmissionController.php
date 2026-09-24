<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Application\Property\Query\GetPropertySubmissionWorkspaceQuery;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Twig\Environment;

final readonly class PropertySubmissionController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private WorkspaceCompositionResolver $workspaces,
        private PagePresentationFactory $pages,
        private PropertySubmissionPresenter $presenter,
    ) {
    }

    public function index(Request $request,string $id): Response
    {
        $tenant=$this->manager();
        if($tenant instanceof Response)return $tenant;
        $submissionId=(int)$id;
        if($submissionId<=0)throw new NotFoundHttpException('Property submission not found.');

        $context=new WebExtensionContext(
            organizationId:$tenant->organizationId()->value(),
            role:$tenant->role()->value(),
            surface:'workspace',
            activeSection:'properties',
            activeItem:'submissions',
        );
        $shell=$this->shells->create($tenant,$context,'Property Submission',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Properties','/property/manage'),
            new ShellBreadcrumb('Moderation','/property/submissions'),
            new ShellBreadcrumb('Submission #'.$submissionId),
        ]);
        $workspace=$this->workspaces->resolve(
            $tenant,$context,'property.submission',new EntityRef('property.submission',(string)$submissionId),
        );

        try{
            $data=$this->queries->ask(new GetPropertySubmissionWorkspaceQuery(
                $tenant->organizationId(),$submissionId,
            ));
            if(!is_array($data))throw new NotFoundHttpException('Property submission not found.');
            $submission=$this->presenter->present($data,$submissionId);

            return $this->render([
                'shell'=>$shell,
                'workspace'=>$workspace,
                'page'=>$this->pages->create(PageArchetype::EntityWorkspace,$this->patterns(),$submission->state()),
                'submission'=>$submission,
            ]);
        }catch(NotFoundHttpException $error){
            throw $error;
        }catch(Throwable $error){
            error_log('property.submission.read_failed '.$error->getMessage());
            $submission=$this->presenter->present([],$submissionId,'Property submission тимчасово недоступна.');
            return $this->render([
                'shell'=>$shell,
                'workspace'=>$workspace,
                'page'=>$this->pages->create(PageArchetype::EntityWorkspace,$this->patterns(),'error'),
                'submission'=>$submission,
            ],Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!$tenant->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return ['WorkspaceHeader','EntityHeader','KpiStrip','ContextPanel','EmptyState','ErrorState'];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables,int $status=Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/property/submission.html.twig',$variables),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }
}
