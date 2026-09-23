<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\AI\GrowthExperimentDecisionPrompt;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionGatewayInterface;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthExperimentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\DTO\ExperimentDecisionDraft;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\ExperimentDecisionRecommendation;
use Domains\Growth\Domain\ExperimentDecisionType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthExperimentDecisionService implements GrowthExperimentDecisionBoundary
{
    private const MIN_TOTAL_ASSIGNED_FOR_PROMOTION=20;
    private const MIN_PER_VARIANT_ASSIGNED_FOR_PROMOTION=5;

    public function __construct(
        private GrowthExperimentRepositoryInterface $experiments,
        private GrowthExperimentDecisionRepositoryInterface $decisions,
        private GrowthExperimentDecisionGatewayInterface $gateway,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);

        $runId='GEDR-'.$this->stableId($organizationId.':experiment_decision_run:'.$idempotencyKey);
        $recommendationId='GEDC-'.$this->stableId($organizationId.':experiment_decision_recommendation:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'experiment_id'=>$experimentId,
            'prompt_version'=>GrowthExperimentDecisionPrompt::PROMPT_VERSION,
            'schema_version'=>GrowthExperimentDecisionPrompt::SCHEMA_VERSION,
        ]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey,$runId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'generate_experiment_decision',$idempotencyKey,$fingerprint)){
                $run=$this->decisions->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Growth experiment decision receipt exists but run was not found.');
                $storedId=$run['recommendation_id']??null;
                $recommendation=null;
                if(is_string($storedId)&&$storedId!==''){
                    $recommendation=$this->decisions->viewRecommendation($organizationId,$storedId)
                        ?? throw new InvalidArgumentException('Growth experiment decision replay references a missing recommendation.');
                }
                return ['replay'=>['run'=>$run,'recommendation'=>$recommendation,'replayed'=>true]];
            }

            $experiment=$this->experiments->viewExperiment($organizationId,$experimentId)
                ?? throw new InvalidArgumentException('Growth experiment was not found.');
            if(($experiment['status']??null)!=='completed'){
                throw new InvalidArgumentException('Growth experiment decision requires a completed experiment.');
            }
            $attribution=$this->experiments->attributionReport($organizationId,$experimentId);
            $context=$this->context($experiment,$attribution);

            $this->decisions->createRun(
                $organizationId,$runId,$experimentId,$context,
                GrowthExperimentDecisionPrompt::PROMPT_VERSION,
                GrowthExperimentDecisionPrompt::SCHEMA_VERSION,$actorId,
            );
            $this->publish(
                GrowthEventType::EXPERIMENT_DECISION_RUN_STARTED,$organizationId,'growth_experiment',$experimentId,[
                    'run_id'=>$runId,
                    'total_assigned'=>$context['sample']['total_assigned'],
                    'promotion_sample_floor_met'=>$context['sample']['promotion_sample_floor_met'],
                    'prompt_version'=>GrowthExperimentDecisionPrompt::PROMPT_VERSION,
                    'schema_version'=>GrowthExperimentDecisionPrompt::SCHEMA_VERSION,
                ],$actorId,$correlationId,
            );

            return ['replay'=>null,'context'=>$context];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $context=$setup['context']??null;
        if(!is_array($context))throw new InvalidArgumentException('Growth experiment decision setup lost context.');

        try{
            $draft=$this->gateway->recommend($organizationId,$experimentId,$correlationId,$context);
            $this->validateDraft($draft,$context);
        }catch(Throwable $error){
            $summary=mb_substr(
                trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),
                0,2000,
            );
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$experimentId,$runId,$idempotencyKey,$summary
            ):array{
                $this->decisions->failRun($organizationId,$runId,$summary);
                $this->publish(
                    GrowthEventType::EXPERIMENT_DECISION_RUN_FAILED,$organizationId,'growth_experiment',$experimentId,[
                        'run_id'=>$runId,'error'=>$summary,
                    ],$actorId,$correlationId,
                );
                $this->appendAudit(
                    $organizationId,$actorId,$correlationId,'growth.experiment_decision.failed',
                    'growth_experiment',$experimentId,$idempotencyKey,['run_id'=>$runId,'error'=>$summary],
                );
                return [
                    'run'=>$this->decisions->viewRun($organizationId,$runId)
                        ?? throw new InvalidArgumentException('Failed Growth experiment decision run could not be read back.'),
                    'recommendation'=>null,
                ];
            });
        }

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$experimentId,$idempotencyKey,
            $runId,$recommendationId,$draft
        ):array{
            $superseded=$this->decisions->supersedeProposedForExperiment(
                $organizationId,$experimentId,'Superseded by a newer experiment decision recommendation.',$actorId,
            );
            foreach($superseded as $supersededId){
                $this->publish(
                    GrowthEventType::EXPERIMENT_DECISION_RECOMMENDATION_SUPERSEDED,
                    $organizationId,'growth_experiment',$experimentId,[
                        'recommendation_id'=>$supersededId,
                        'replacement_recommendation_id'=>$recommendationId,
                    ],$actorId,$correlationId,
                );
            }

            $recommendation=new ExperimentDecisionRecommendation(
                $recommendationId,OrganizationId::fromString($organizationId),$experimentId,
                $draft->decisionType,$draft->promotedVariantKey,$draft->rationale,
                $draft->evidenceIds,$draft->risks,$draft->assumptions,$draft->confidence,
                $draft->provider,$draft->model,$draft->promptVersion,$draft->schemaVersion,$this->now(),
            );
            $this->decisions->createRecommendation($recommendation,$runId,$actorId);
            $this->decisions->completeRun(
                $organizationId,$runId,$recommendationId,$draft->provider,$draft->model,
                $draft->inputTokens,$draft->outputTokens,$draft->costAmount,$draft->costCurrency,
            );

            $this->publish(
                GrowthEventType::EXPERIMENT_DECISION_RUN_COMPLETED,$organizationId,'growth_experiment',$experimentId,[
                    'run_id'=>$runId,'recommendation_id'=>$recommendationId,
                    'decision_type'=>$draft->decisionType->value,
                    'promoted_variant_key'=>$draft->promotedVariantKey,
                ],$actorId,$correlationId,
            );
            $this->publish(
                GrowthEventType::EXPERIMENT_DECISION_RECOMMENDATION_CREATED,
                $organizationId,'growth_experiment',$experimentId,[
                    'recommendation_id'=>$recommendationId,
                    'decision_type'=>$draft->decisionType->value,
                    'promoted_variant_key'=>$draft->promotedVariantKey,
                    'confidence'=>$draft->confidence,
                    'evidence_ids'=>$draft->evidenceIds,
                ],$actorId,$correlationId,
            );
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.experiment_decision.recommended',
                'growth_experiment',$experimentId,$idempotencyKey,[
                    'run_id'=>$runId,'recommendation_id'=>$recommendationId,
                    'decision_type'=>$draft->decisionType->value,
                    'promoted_variant_key'=>$draft->promotedVariantKey,
                ],
            );

            return [
                'run'=>$this->decisions->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Completed Growth experiment decision run could not be read back.'),
                'recommendation'=>$this->decisions->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Created Growth experiment decision recommendation could not be read back.'),
            ];
        });
    }

    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,
        string $recommendationId,string $reason,string $idempotencyKey
    ):array {
        return $this->decide(
            true,$organizationId,$actorId,$correlationId,$experimentId,$recommendationId,$reason,$idempotencyKey,
        );
    }

    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $experimentId,
        string $recommendationId,string $reason,string $idempotencyKey
    ):array {
        return $this->decide(
            false,$organizationId,$actorId,$correlationId,$experimentId,$recommendationId,$reason,$idempotencyKey,
        );
    }

    public function decisionBrief(string $organizationId,string $experimentId):array
    {
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        return [
            'experiment'=>$this->experiments->viewExperiment($organizationId,$experimentId)
                ?? throw new InvalidArgumentException('Growth experiment was not found.'),
            'attribution'=>$this->experiments->attributionReport($organizationId,$experimentId),
            'latest_recommendation'=>$this->decisions->latestRecommendation($organizationId,$experimentId),
        ];
    }

    /** @return array<string,mixed> */
    private function decide(
        bool $accept,string $organizationId,int $actorId,string $correlationId,string $experimentId,
        string $recommendationId,string $reason,string $idempotencyKey
    ):array {
        $experimentId=$this->bounded(trim($experimentId),'experimentId',80);
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $operation=$accept?'accept_experiment_decision':'dismiss_experiment_decision';
        $fingerprint=$this->fingerprint([
            'experiment_id'=>$experimentId,'recommendation_id'=>$recommendationId,
            'decision'=>$accept?'accept':'dismiss','reason'=>$reason,
        ]);

        return $this->transactions->transactional(function()use(
            $accept,$organizationId,$actorId,$correlationId,$experimentId,
            $recommendationId,$reason,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return [
                    'recommendation'=>$this->decisions->viewRecommendation($organizationId,$recommendationId)
                        ?? throw new InvalidArgumentException('Growth experiment decision replay recommendation was not found.'),
                    'replayed'=>true,
                ];
            }

            $recommendation=$this->decisions->lockRecommendation($organizationId,$recommendationId);
            if($recommendation->experimentId!==$experimentId){
                throw new InvalidArgumentException('Growth experiment decision recommendation belongs to another experiment.');
            }

            if($accept)$recommendation->accept($reason); else $recommendation->dismiss($reason);
            $this->decisions->updateRecommendation($recommendation,$actorId);

            $event=$accept
                ?GrowthEventType::EXPERIMENT_DECISION_RECOMMENDATION_ACCEPTED
                :GrowthEventType::EXPERIMENT_DECISION_RECOMMENDATION_DISMISSED;
            $action=$accept?'growth.experiment_decision.accepted':'growth.experiment_decision.dismissed';

            $this->publish($event,$organizationId,'growth_experiment',$experimentId,[
                'recommendation_id'=>$recommendationId,
                'decision_type'=>$recommendation->decisionType->value,
                'promoted_variant_key'=>$recommendation->promotedVariantKey,
                'reason'=>$reason,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$action,'growth_experiment',$experimentId,
                $idempotencyKey,['recommendation_id'=>$recommendationId,'reason'=>$reason],
            );

            return [
                'recommendation'=>$this->decisions->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Growth experiment decision recommendation could not be read back.'),
            ];
        });
    }

    /**
     * @param array<string,mixed> $experiment
     * @param array<string,mixed> $attribution
     * @return array<string,mixed>
     */
    private function context(array $experiment,array $attribution):array
    {
        $variants=$attribution['variants']??null;
        if(!is_array($variants)||!array_is_list($variants)||count($variants)<2){
            throw new InvalidArgumentException('Growth experiment decision requires attribution for at least two variants.');
        }

        $evidence=[];
        $allowedEvidence=[];
        $variantKeys=[];
        $totalAssigned=0;
        $minimumAssigned=null;

        foreach($variants as $variant){
            if(!is_array($variant)||array_is_list($variant))continue;
            $key=$variant['key']??null;
            if(!is_string($key)||$key==='')continue;

            $assigned=(int)($variant['assigned']??0);
            $totalAssigned+=$assigned;
            $minimumAssigned=$minimumAssigned===null?$assigned:min($minimumAssigned,$assigned);
            $variantKeys[]=$key;

            $evidenceId=$this->metricId('variant',$experiment['experiment_id'].':'.$key);
            $allowedEvidence[]=$evidenceId;
            $evidence[]=[
                'evidence_id'=>$evidenceId,
                'kind'=>'variant_attribution',
                'variant_key'=>$key,
                'variant_name'=>$variant['name']??null,
                'assigned'=>$assigned,
                'primary_outcome'=>$variant['primary_outcome']??null,
                'primary_count'=>(int)($variant['primary_count']??0),
                'primary_rate'=>(float)($variant['primary_rate']??0.0),
                'outcomes'=>$variant['outcomes']??[],
                'won_value_by_currency'=>$variant['won_value_by_currency']??[],
            ];
        }

        if(count($variantKeys)<2)throw new InvalidArgumentException('Growth experiment decision has insufficient variant attribution.');

        $floorMet=$totalAssigned>=self::MIN_TOTAL_ASSIGNED_FOR_PROMOTION
            &&($minimumAssigned??0)>=self::MIN_PER_VARIANT_ASSIGNED_FOR_PROMOTION;

        $overallEvidenceId=$this->metricId('overall',(string)$experiment['experiment_id']);
        $allowedEvidence[]=$overallEvidenceId;
        array_unshift($evidence,[
            'evidence_id'=>$overallEvidenceId,
            'kind'=>'experiment_summary',
            'total_assigned'=>$totalAssigned,
            'minimum_variant_assigned'=>$minimumAssigned??0,
            'minimum_total_for_promotion'=>self::MIN_TOTAL_ASSIGNED_FOR_PROMOTION,
            'minimum_per_variant_for_promotion'=>self::MIN_PER_VARIANT_ASSIGNED_FOR_PROMOTION,
            'promotion_sample_floor_met'=>$floorMet,
            'attribution_rule'=>$attribution['attribution_rule']??null,
        ]);

        $variantKeys=array_values(array_unique($variantKeys));
        sort($variantKeys,SORT_STRING);
        $allowedEvidence=array_values(array_unique($allowedEvidence));
        sort($allowedEvidence,SORT_STRING);

        return [
            'experiment'=>[
                'experiment_id'=>$experiment['experiment_id']??null,
                'name'=>$experiment['name']??null,
                'hypothesis'=>$experiment['hypothesis']??null,
                'dimension'=>$experiment['dimension']??null,
                'primary_outcome'=>$experiment['primary_outcome']??null,
                'status'=>$experiment['status']??null,
                'started_at'=>$experiment['started_at']??null,
                'ended_at'=>$experiment['ended_at']??null,
                'variants'=>$experiment['variants']??[],
            ],
            'sample'=>[
                'total_assigned'=>$totalAssigned,
                'minimum_variant_assigned'=>$minimumAssigned??0,
                'minimum_total_for_promotion'=>self::MIN_TOTAL_ASSIGNED_FOR_PROMOTION,
                'minimum_per_variant_for_promotion'=>self::MIN_PER_VARIANT_ASSIGNED_FOR_PROMOTION,
                'promotion_sample_floor_met'=>$floorMet,
            ],
            'evidence'=>$evidence,
            'allowed_evidence_ids'=>$allowedEvidence,
            'allowed_variant_keys'=>$variantKeys,
            'allowed_decision_types'=>ExperimentDecisionType::values(),
        ];
    }

    /** @param array<string,mixed> $context */
    private function validateDraft(ExperimentDecisionDraft $draft,array $context):void
    {
        $allowedEvidence=array_fill_keys(
            array_values(array_filter($context['allowed_evidence_ids']??[],'is_string')),true
        );
        foreach($draft->evidenceIds as $evidenceId){
            if(!isset($allowedEvidence[$evidenceId])){
                throw new InvalidArgumentException(
                    'Growth experiment decision LLM cited evidence outside deterministic attribution context: '.$evidenceId
                );
            }
        }

        $allowedVariants=array_fill_keys(
            array_values(array_filter($context['allowed_variant_keys']??[],'is_string')),true
        );
        if($draft->decisionType===ExperimentDecisionType::PromoteVariant){
            if($draft->promotedVariantKey===null||!isset($allowedVariants[$draft->promotedVariantKey])){
                throw new InvalidArgumentException('Growth experiment decision selected unknown promoted variant.');
            }
            if(($context['sample']['promotion_sample_floor_met']??false)!==true){
                throw new InvalidArgumentException(
                    'Growth experiment promote_variant recommendation requires minimum sample floor.'
                );
            }
        }
    }

    private function metricId(string $kind,string $value):string
    {
        return 'experiment:'.$kind.':'.substr(hash('sha256',$value),0,16);
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,
        array $payload,int $actorId,string $correlationId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,
        string $subjectType,string $subjectId,string $idempotencyKey,array $data=[]
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.experiment_decision','USER',(string)$actorId,
            $subjectType,$subjectId,null,[
                'action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data,
            ],$correlationId,$this->now(),
        ));
    }

    private function stableId(string $value):string
    {
        return strtoupper(substr(hash('sha256',$value),0,20));
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode(
            $normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function now():DateTimeImmutable
    {
        return new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }
}
