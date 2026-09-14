<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\AI\{AiGatewayInterface,AiOperation,AiOperationDefinition,AiRequest,ContextSanitizer,StructuredOutputValidator};
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use UnexpectedValueException;

final readonly class FactExtractionService
{
    public function __construct(private AiGatewayInterface $ai,private StructuredOutputValidator $validator=new StructuredOutputValidator(),private ContextSanitizer $sanitizer=new ContextSanitizer()){}
    public function extract(string $organizationId,string $diagnosticId,string $question,string $answer,CompiledDiagnosticPack $pack,array $currentFacts=[],array $companyContext=[]):FactExtractionResult
    {
        $schema=['required'=>['facts','metrics','evidence','uncertainties','contradictions','missing_information'],'properties'=>array_fill_keys(['facts','metrics','evidence','uncertainties','contradictions','missing_information'],['type'=>'array'])];
        $context=$this->sanitizer->sanitize(['question'=>$question,'answer'=>$answer,'allowed_facts'=>array_keys($pack->factsById),'allowed_metrics'=>array_keys($pack->metricsById),'current_facts'=>array_keys($currentFacts),'company_context'=>$companyContext]);
        $response=$this->ai->execute(new AiOperationDefinition(AiOperation::ExtractFacts,'diagnostic.fact_extraction','fact_extractor:v1','1.0',1800),new AiRequest($organizationId,$diagnosticId,$context,$schema));
        $out=$this->validator->validate($response->output,$schema);$facts=[];$seen=[];
        foreach($out['facts'] as $item){if(!is_array($item)||!isset($item['key'],$item['value'],$item['provenance'],$item['confidence']))throw new UnexpectedValueException('Invalid extracted fact item.');$key=(string)$item['key'];if(!isset($pack->factsById[$key]))throw new UnexpectedValueException('AI hallucinated methodology fact: '.$key);if(isset($seen[$key]))continue;$definition=$pack->factsById[$key];$this->assertType($item['value'],$definition->type);$facts[]=new ExtractedFact($key,$item['value'],$definition->type,strtoupper((string)$item['provenance']),(float)$item['confidence'],array_values(array_unique($item['evidence_ids']??[])));$seen[$key]=true;}
        foreach($out['metrics'] as $metric)if(!is_array($metric)||!isset($metric['key'])||!isset($pack->metricsById[(string)$metric['key']]))throw new UnexpectedValueException('AI hallucinated methodology metric.');
        return new FactExtractionResult($facts,$out['evidence'],$out['metrics'],$out['uncertainties'],$out['contradictions'],$out['missing_information']);
    }
    private function assertType(mixed $value,string $type):void{$ok=match($type){'boolean'=>is_bool($value),'integer'=>is_int($value),'number','float','percentage','duration'=>is_int($value)||is_float($value),'string','enum'=>is_string($value),default=>true};if(!$ok)throw new UnexpectedValueException('Extracted fact has incompatible value type.');}
}
