<?php
declare(strict_types=1);
namespace App\Web\Operations;

use App\Application\Operations\Command\OperationsMutationCommand;
use App\Security\SessionCsrfValidator;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ControlCenterPageController
{
 public function __construct(
  private PhtmlRenderer $renderer,private TenantContextProviderInterface $tenants,private NavigationBuilder $navigation,
  private OperationsReadModelInterface $operations,private CommandBusInterface $commands,private SessionCsrfValidator $csrf,
 ){}
 public function index(Request $r):Response
 {
  $t=$this->manager();if($t instanceof Response)return$t;$status=200;$overview=[];$pageStatus=null;
  try{$overview=$this->operations->overview($t->organizationId()->value(),max(1,min(100,(int)$r->query->get('limit',30))));}
  catch(Throwable $e){error_log('cos.control_center.read_failed '.$e->getMessage());$status=503;$pageStatus='COS Control Center тимчасово недоступний.';}
  $role=$t->role()->value();
  return new Response($this->renderer->render($r,'cos/index',[
   'title'=>'COS Control Center','metaTitle'=>'COS Control Center | Terra Nova COS','metaRobots'=>'noindex,nofollow',
   'interfaceSurface'=>'workspace','workspaceSection'=>'cos','workspaceActive'=>'cos','workspaceActiveSection'=>$this->navigation->activeSection('cos'),
   'pageAssetEntries'=>['cos-control-center'],'csrfToken'=>$this->csrf->token($r),'actionStatus'=>(string)$r->query->get('status_message',''),
   'pageStatus'=>$pageStatus,'overview'=>$overview,'currentUser'=>['id'=>(int)$t->userId()->value(),'role'=>$role],
   'role'=>$role,'isTeam'=>true,'isAdmin'=>$t->isAdmin(),'workspaceNavigation'=>$this->navigation->workspace($t),
  ]),$status,['Content-Type'=>'text/html; charset=UTF-8']);
 }
 public function execute(Request $r,string $id):Response{return$this->mutate($r,$id,OperationsMutationCommand::EXECUTE_ACTION);}
 public function approve(Request $r,string $id):Response{return$this->mutate($r,$id,OperationsMutationCommand::APPROVE);}
 public function reject(Request $r,string $id):Response{return$this->mutate($r,$id,OperationsMutationCommand::REJECT);}
 private function mutate(Request $r,string $id,string $op):Response
 {
  $t=$this->manager();if($t instanceof Response)return$t;
  if(!$this->csrf->isValid($r))return new Response('Invalid CSRF token.',403);
  try{$result=$this->commands->dispatch(new OperationsMutationCommand($t->organizationId()->value(),(int)$t->userId()->value(),$op,$id,$r->request->all(),$this->correlation($r)));$message=is_array($result)?(string)($result['status']??'OK'):'OK';}
  catch(Throwable $e){$message='Помилка: '.$e->getMessage();}
  $return=ltrim(trim((string)$r->request->get('return_url','')),'/');
  if($return!=='cos/control-center'&&!preg_match('#^client-case/show/[1-9][0-9]*$#',$return))$return='cos/control-center';
  return new RedirectResponse('/'.$return.'?status_message='.rawurlencode($message));
 }
 private function correlation(Request $r):string{$v=$r->attributes->get('_cos_correlation_id');return$v instanceof CorrelationId?$v->value():CorrelationId::generate()->value();}
 private function manager():TenantContext|Response{$t=$this->tenants->current();if($t===null)return new RedirectResponse('/auth/login');if(!$t->isManager())return new Response('Forbidden',403);return$t;}
}
