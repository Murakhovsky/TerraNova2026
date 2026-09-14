<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2); require $root . '/vendor/autoload.php';
use Domains\Sales\Model\PipelineDefinition; use Domains\Sales\Model\PipelineStageDefinition;
$stages=[new PipelineStageDefinition('new','NEW','New',10,false,false,false,5),new PipelineStageDefinition('won','WON','Won',20,true,true,false,100),new PipelineStageDefinition('lost','LOST','Lost',30,true,false,true,0)];
$pipeline=new PipelineDefinition('pipeline','default','default-sales','Default Sales',$stages,'new');
if(count($pipeline->stages)!==3||$pipeline->initialStage()->id!=='new')throw new RuntimeException('Pipeline invariants failed.');
try{new PipelineStageDefinition('bad','BAD','Bad',1,false,true,false,90);throw new RuntimeException('Non-terminal won stage accepted.');}catch(DomainException){}
try{new PipelineDefinition('invalid','default','invalid','Invalid',$stages,'missing');throw new RuntimeException('Missing initial stage accepted.');}catch(DomainException){}
echo "Sales pipeline invariants passed.\n";
