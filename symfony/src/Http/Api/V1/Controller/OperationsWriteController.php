<?php
declare(strict_types=1);
namespace App\Http\Api\V1\Controller;
use App\Application\Operations\Command\OperationsMutationCommand;
use App\Security\LegacySessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;
final readonly class OperationsWriteController
{
 public function __construct(private CommandBusInterface $commands,private TenantContextProviderInterface $tenants,private LegacySessionCsrfValidator $csrf){}
 public function execute(Request $r,string $id):JsonResponse{return $this->mutate($r,OperationsMutationCommand::EXECUTE_ACTION,$id,[],202);}
 public function dismiss(Request $r,string $id):JsonResponse{return $this->mutate($r,OperationsMutationCommand::DISMISS_ACTION,$id,[]);}
 public function approve(Request $r,string $id):JsonResponse{return $this->mutate($r,OperationsMutationCommand::APPROVE,$id,$this->input($r));}
 public function reject(Request $r,string $id):JsonResponse{return $this->mutate($r,OperationsMutationCommand::REJECT,$id,$this->input($r));}
 private function mutate(Request $request,string $operation,string $id,array $input,int $status=200):JsonResponse
 {
  $t=$this->tenants->current();if($t===null||!$t->isManager()||!$t->allows(TenantPermissions::MANAGE))return new JsonResponse(['ok'=>false,'error'=>'manager_required','message'=>'Manager authorization required.'],403);
  if(!$this->csrf->isValid($request))return new JsonResponse(['ok'=>false,'error'=>'invalid_csrf_token','message'=>'Invalid CSRF token.'],400);
  $actor=$t->userId()->value();if(!ctype_digit($actor)||(int)$actor<=0)return new JsonResponse(['ok'=>false,'error'=>'invalid_actor','message'=>'Authenticated actor is invalid.'],403);
  $c=$request->attributes->get('_cos_correlation_id');$cid=$c instanceof CorrelationId?$c->value():CorrelationId::generate()->value();
  try{$data=$this->commands->dispatch(new OperationsMutationCommand($t->organizationId()->value(),(int)$actor,$operation,$id,$input,$cid));return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
  catch(Throwable $e){$root=$e instanceof HandlerFailedException&&$e->getPrevious() instanceof Throwable?$e->getPrevious():$e;$m=$root->getMessage();$n=strtolower($m);$code=str_contains($n,'not found')||str_contains($n,'does not exist')?404:(str_contains($n,'current status')||str_contains($n,'pending')||str_contains($n,'approval')||str_contains($n,'already')?409:($root instanceof DomainException?422:500));return new JsonResponse(['ok'=>false,'error'=>'operations_mutation_failed','message'=>$m],$code);}
 }
 private function input(Request $r):array{$d=json_decode((string)$r->getContent(),true);return is_array($d)&&!array_is_list($d)?$d:$r->request->all();}
}
