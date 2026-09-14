<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
use InvalidArgumentException;
final class ExplainabilityGraph
{
    private array $nodes=[];private array $upstream=[];
    public function add(string $id,string $type,array $data=[],array $upstream=[]):void{if($id===''||isset($this->nodes[$id]))throw new InvalidArgumentException('Explainability node identity must be unique.');foreach($upstream as $ref)if(!isset($this->nodes[$ref]))throw new InvalidArgumentException('Unknown explainability upstream: '.$ref);$this->nodes[$id]=['id'=>$id,'type'=>$type,'data'=>$data];$this->upstream[$id]=array_values(array_unique($upstream));}
    public function explain(string $id):array{if(!isset($this->nodes[$id]))throw new InvalidArgumentException('Unknown explainability node: '.$id);$seen=[];$walk=function($node)use(&$walk,&$seen){if(isset($seen[$node]))return;$seen[$node]=$this->nodes[$node];foreach($this->upstream[$node]??[] as $up)$walk($up);};$walk($id);return ['subject'=>$this->nodes[$id],'trace'=>array_values($seen),'upstream'=>$this->upstream[$id]??[]];}
}
