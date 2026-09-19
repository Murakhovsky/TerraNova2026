<?php
declare(strict_types=1);
namespace App\Controller;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;
final readonly class DependencyHealthController
{
 public function __construct(private OperationsReadModelInterface $operations){}
 public function __invoke():JsonResponse{try{$h=$this->operations->health();$ok=($h['status']??null)==='ok';return new JsonResponse(['status'=>$ok?'ok':'degraded','service'=>'cos-symfony','dependencies'=>['business_store'=>$ok?'ok':'degraded']],$ok?200:503);}catch(Throwable){return new JsonResponse(['status'=>'unavailable','service'=>'cos-symfony','dependencies'=>['business_store'=>'unavailable']],503);}}
}
