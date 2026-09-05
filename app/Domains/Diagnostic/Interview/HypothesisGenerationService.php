<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperation,AiOperationDefinition,AiRequest,StructuredOutputValidator};
use Domains\Diagnostic\Model\{Hypothesis,HypothesisStatus};

final readonly class HypothesisGenerationService
{
    public function __construct(private AiGatewayInterface $ai,private StructuredOutputValidator $validator=new StructuredOutputValidator()){}
    public function generate(string $organizationId,string $diagnosticId,array $validatedFindings,array $evidenceIds):array{$schema=['required'=>['hypotheses'],'properties'=>['hypotheses'=>['type'=>'array']]];$out=$this->validator->validate($this->ai->execute(new AiOperationDefinition(AiOperation::GenerateHypotheses,'diagnostic.hypotheses','hypothesis_generator:v1','configured','1.0'),new AiRequest($organizationId,$diagnosticId,['findings'=>$validatedFindings,'available_evidence'=>$evidenceIds],$schema))->output,$schema);$items=[];foreach($out['hypotheses'] as $i=>$h){if(!is_array($h)||trim((string)($h['hypothesis']??''))==='')continue;$support=array_values(array_intersect($evidenceIds,$h['supporting_evidence']??[]));$contrary=array_values(array_intersect($evidenceIds,$h['contradicting_evidence']??[]));$items[]=new Hypothesis(substr(hash('sha256',$diagnosticId.':'.$i.':'.$h['hypothesis']),0,32),(string)$h['hypothesis'],HypothesisStatus::Open,min(.69,max(0,(float)($h['confidence_estimate']??.3))),$support,$contrary,array_values($h['required_evidence']??[]));}return $items;}
}
