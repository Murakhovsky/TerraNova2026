<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\UseCase\CaptureDiagnosticEvidence;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;

final readonly class DiagnosticEvidencePipeline
{
    public function __construct(
        private DiagnosticRuntimeRepositoryInterface $runtime,
        private DiagnosticSessionRepositoryInterface $sessions,
        private CaptureDiagnosticEvidence $captureEvidence,
    ) {}

    /** @param array<string,mixed> $input */
    public function ingest(string $organizationId,string $sessionId,array $input,string $actorId,string $idempotencyKey): array
    {
        $runtime=$this->runtime->get($organizationId,$sessionId)??throw new DomainException('Diagnostic runtime was not found.');
        if(($runtime['status']??null)!=='active') throw new DomainException('Diagnostic runtime is not active.');
        $session=$this->sessions->get($organizationId,$sessionId)??throw new DomainException('Diagnostic session was not found.');

        $evidenceId=substr(hash('sha256',$organizationId.':'.$sessionId.':'.$idempotencyKey),0,64);
        foreach($session->evidence() as $existing){
            if($existing->id===$evidenceId) return ['evidence_id'=>$evidenceId,'replayed'=>true,'facts_ingested'=>0,'metrics_ingested'=>0];
        }

        $type=EvidenceType::tryFrom(strtolower(trim((string)($input['type']??''))))??throw new DomainException('Unsupported evidence type.');
        $title=trim((string)($input['title']??'')); $source=trim((string)($input['source']??''));
        if($title===''||$source==='') throw new DomainException('Evidence title and source are required.');

        $reliability=(float)($input['reliability']??0.8); $directness=(float)($input['directness']??0.8);
        $sourceReference=($v=trim((string)($input['source_reference']??'')))!==''?$v:null;
        $collectionMethod=trim((string)($input['collection_method']??'api'));
        $scope=($v=trim((string)($input['scope']??'')))!==''?$v:null;
        $sampleSize=isset($input['sample_size'])?(int)$input['sample_size']:null; $rawValue=$input['raw_value']??null;
        $metadata=is_array($input['metadata']??null)?$input['metadata']:[];
        $metadata += ['source_reference'=>$sourceReference,'collection_method'=>$collectionMethod,'reliability'=>$reliability,'directness'=>$directness,'scope'=>$scope,'sample_size'=>$sampleSize,'raw_value'=>$rawValue];

        $evidence=new Evidence($evidenceId,$type,$title,$source,new DateTimeImmutable(),$metadata,$sourceReference,$collectionMethod,$reliability,$directness,$scope,$sampleSize,$rawValue);
        $this->captureEvidence->execute($organizationId,$sessionId,$evidence,'USER',$actorId);

        $state=is_array($runtime['state']??null)?$runtime['state']:[];
        $state['facts']=is_array($state['facts']??null)?$state['facts']:[];
        $state['metrics']=is_array($state['metrics']??null)?$state['metrics']:[];
        $state['contradictions']=is_array($state['contradictions']??null)?$state['contradictions']:[];
        $now=(new DateTimeImmutable())->format(DATE_ATOM);

        $facts=0;
        foreach($this->items($input['facts']??[]) as $fact){
            $key=trim((string)($fact['key']??'')); if($key===''||!array_key_exists('value',$fact)) continue;
            $previous=$state['facts'][$key]??null;
            if(is_array($previous)&&array_key_exists('value',$previous)&&$previous['value']!==$fact['value']){
                $state['contradictions'][]=['fact'=>$key,'before'=>$previous['value'],'after'=>$fact['value'],'evidence_id'=>$evidenceId];
            }
            $state['facts'][$key]=['value'=>$fact['value'],'value_type'=>(string)($fact['value_type']??get_debug_type($fact['value'])),'confidence'=>max(0.0,min(1.0,(float)($fact['confidence']??$reliability))),'source'=>strtoupper($type->value),'evidence_ids'=>[$evidenceId],'updated_at'=>$now];
            $facts++;
        }

        $metrics=0;
        foreach($this->items($input['metrics']??[]) as $metric){
            $key=trim((string)($metric['key']??'')); if($key===''||!isset($metric['value'])||!is_numeric($metric['value'])) continue;
            $state['metrics'][$key]=['value'=>(float)$metric['value'],'evidence_ids'=>[$evidenceId],'updated_at'=>$now]; $metrics++;
        }

        $revision=max((int)($runtime['state_revision']??0),(int)($state['revision']??0))+1; $state['revision']=$revision;
        $this->runtime->saveState($organizationId,$sessionId,$state,isset($runtime['current_question_id'])?(string)$runtime['current_question_id']:null,$revision);
        return ['evidence_id'=>$evidenceId,'replayed'=>false,'facts_ingested'=>$facts,'metrics_ingested'=>$metrics,'revision'=>$revision];
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $value): array
    {
        if(!is_array($value)) return []; $out=[]; foreach($value as $item) if(is_array($item)) $out[]=$item; return $out;
    }
}
