<?php
declare(strict_types=1);
namespace App\Http\Api\V1\Controller;
use Kernel\Operations\Service\OperationsSectionReader;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;
final readonly class OperationsReadController
{
 public function __construct(private OperationsSectionReader $operations,private TenantContextProviderInterface $tenants){}
 public function actions():JsonResponse{return $this->section('actions');}
 public function action(string $id):JsonResponse{return $this->section('actions',$id);}
 public function approvals():JsonResponse{return $this->section('approvals');}
 public function agents():JsonResponse{return $this->section('agent_runs');}
 public function rules():JsonResponse{return $this->section('rules');}
 public function events():JsonResponse{return $this->section('events');}
 public function audit():JsonResponse{return $this->section('audit');}
 private function section(string $section,?string $id=null):JsonResponse
 {
  $context=$this->tenants->current();if($context===null)return new JsonResponse(['ok'=>false,'error'=>'Authenticated tenant context required.'],403);
  try{$org=$context->organizationId()->value();if($id!==null){$item=$this->operations->item($org,$section,$id,100);return $item!==null?new JsonResponse(['ok'=>true,'data'=>$item]):new JsonResponse(['ok'=>false,'error'=>'Resource not found.'],404);}
  return new JsonResponse(['ok'=>true,'data'=>$this->operations->section($org,$section,100)]);}catch(Throwable){return new JsonResponse(['ok'=>false,'error'=>'Operations query failed.'],500);}
 }
}
