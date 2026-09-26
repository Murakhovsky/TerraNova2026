<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Growth\Application\AI\GrowthOptimizationPrompt;
use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Domains\Growth\Application\Contract\GrowthDecisionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthOptimizationBoundary;
use Domains\Growth\Application\Contract\GrowthOptimizationGatewayInterface;
use Domains\Growth\Application\Contract\GrowthOptimizationRepositoryInterface;
use Domains\Growth\Application\DTO\LearningOptimizationDraft;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\LearningOptimizationRecommendation;
use Domains\Growth\Domain\OptimizationRecommendationStatus;
use Domains\Growth\Domain\OptimizationTargetType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthOptimizationService implements GrowthOptimizationBoundary
{
    public function __construct(
        private GrowthOptimizationRepositoryInterface $optimization,
        private GrowthOptimizationGatewayInterface $gateway,
        private GrowthIntelligenceRepositoryInterface $intelligenceRepository,
        private GrowthDecisionRepositoryInterface $decisionRepository,
        private GrowthIntelligenceBoundary $intelligence,
        private GrowthDecisionBoundary $decisions,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function generateRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded(trim($organizationId),'organizationId',64);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $runId='GORN-'.$this->stableId($organizationId.':optimization_run:'.$idempotencyKey);
        $recommendationId='GORC-'.$this->stableId($organizationId.':optimization_recommendation:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'prompt_version'=>GrowthOptimizationPrompt::PROMPT_VERSION,
            'schema_version'=>GrowthOptimizationPrompt::SCHEMA_VERSION,
        ]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$runId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'generate_learning_optimization',$idempotencyKey,$fingerprint)){
                $run=$this->optimization->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Growth optimization receipt exists but run was not found.');
                $storedId=$run['recommendation_id']??null;
                $recommendation=null;
                if(is_string($storedId)&&$storedId!==''){
                    $recommendation=$this->optimization->viewRecommendation($organizationId,$storedId)
                        ?? throw new InvalidArgumentException('Growth optimization replay references a missing recommendation.');
                }
                return ['replay'=>['run'=>$run,'recommendation'=>$recommendation,'replayed'=>true]];
            }

            $context=$this->optimization->optimizationContext($organizationId,200);
            $sample=(int)($context['terminal_sample_size']??0);
            $minimum=(int)($context['minimum_terminal_sample']??8);
            if($sample<$minimum){
                throw new InvalidArgumentException('Growth optimization requires at least '.$minimum.' terminal Candidate outcomes; found '.$sample.'.');
            }
            $targets=$context['active_targets']??null;
            if(!is_array($targets)||$this->targetCount($targets)===0){
                throw new InvalidArgumentException('Growth optimization requires at least one active ICP or Qualification Policy target.');
            }

            $this->optimization->createRun(
                $organizationId,$runId,$context,GrowthOptimizationPrompt::PROMPT_VERSION,
                GrowthOptimizationPrompt::SCHEMA_VERSION,$actorId,
            );
            $this->publish(GrowthEventType::OPTIMIZATION_RUN_STARTED,$organizationId,'growth_optimization',$runId,[
                'terminal_sample_size'=>$sample,
                'active_target_count'=>$this->targetCount($targets),
                'prompt_version'=>GrowthOptimizationPrompt::PROMPT_VERSION,
                'schema_version'=>GrowthOptimizationPrompt::SCHEMA_VERSION,
            ],$actorId,$correlationId);
            return ['replay'=>null,'context'=>$context];
        });

        if(is_array($setup['replay']??null))return $setup['replay'];
        $context=$setup['context']??null;
        if(!is_array($context))throw new InvalidArgumentException('Growth optimization setup lost context snapshot.');

        try{
            $draft=$this->gateway->recommend($organizationId,$correlationId,$context);
            $this->validateDraft($draft,$context);
        }catch(Throwable $error){
            $summary=mb_substr(trim($error->getMessage())!==''?get_class($error).': '.$error->getMessage():get_class($error),0,2000);
            return $this->transactions->transactional(function()use(
                $organizationId,$actorId,$correlationId,$runId,$idempotencyKey,$summary
            ):array{
                $this->optimization->failRun($organizationId,$runId,$summary);
                $this->publish(GrowthEventType::OPTIMIZATION_RUN_FAILED,$organizationId,'growth_optimization',$runId,[
                    'error'=>$summary,
                ],$actorId,$correlationId);
                $this->appendAudit(
                    $organizationId,$actorId,$correlationId,'growth.optimization.failed','growth_optimization',$runId,$idempotencyKey,
                    ['error'=>$summary],
                );
                return [
                    'run'=>$this->optimization->viewRun($organizationId,$runId)
                        ?? throw new InvalidArgumentException('Failed Growth optimization run could not be read back.'),
                    'recommendation'=>null,
                ];
            });
        }

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$runId,$recommendationId,$draft
        ):array{
            $superseded=$this->optimization->supersedeProposed(
                $organizationId,'Superseded by a newer learning optimization recommendation.',$actorId,
            );
            foreach($superseded as $supersededId){
                $this->publish(GrowthEventType::OPTIMIZATION_RECOMMENDATION_SUPERSEDED,$organizationId,'growth_optimization',$supersededId,[
                    'replacement_recommendation_id'=>$recommendationId,
                ],$actorId,$correlationId);
            }

            $recommendation=new LearningOptimizationRecommendation(
                $recommendationId,OrganizationId::fromString($organizationId),$draft->targetType,$draft->targetId,
                $draft->baseRevision,$draft->proposedName,$draft->proposedCriteria,$draft->rationale,
                $draft->evidenceIds,$draft->risks,$draft->assumptions,$draft->confidence,
                $draft->provider,$draft->model,$draft->promptVersion,$draft->schemaVersion,$this->now(),
            );
            $this->optimization->createRecommendation($recommendation,$runId,$actorId);
            $this->optimization->completeRun(
                $organizationId,$runId,$recommendationId,$draft->provider,$draft->model,
                $draft->inputTokens,$draft->outputTokens,$draft->costAmount,$draft->costCurrency,
            );
            $this->publish(GrowthEventType::OPTIMIZATION_RUN_COMPLETED,$organizationId,'growth_optimization',$runId,[
                'recommendation_id'=>$recommendationId,'target_type'=>$draft->targetType->value,
                'target_id'=>$draft->targetId,'base_revision'=>$draft->baseRevision,
            ],$actorId,$correlationId);
            $this->publish(GrowthEventType::OPTIMIZATION_RECOMMENDATION_CREATED,$organizationId,'growth_optimization',$recommendationId,[
                'target_type'=>$draft->targetType->value,'target_id'=>$draft->targetId,
                'base_revision'=>$draft->baseRevision,'confidence'=>$draft->confidence,'evidence_ids'=>$draft->evidenceIds,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.optimization.recommended','growth_optimization',$recommendationId,
                $idempotencyKey,['target_type'=>$draft->targetType->value,'target_id'=>$draft->targetId,'base_revision'=>$draft->baseRevision],
            );

            return [
                'run'=>$this->optimization->viewRun($organizationId,$runId)
                    ?? throw new InvalidArgumentException('Completed Growth optimization run could not be read back.'),
                'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Created Growth optimization recommendation could not be read back.'),
            ];
        });
    }

    public function acceptRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $reason,string $idempotencyKey
    ):array {
        return $this->decide(true,$organizationId,$actorId,$correlationId,$recommendationId,$reason,$idempotencyKey);
    }

    public function dismissRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $reason,string $idempotencyKey
    ):array {
        return $this->decide(false,$organizationId,$actorId,$correlationId,$recommendationId,$reason,$idempotencyKey);
    }

    public function materializeRecommendation(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $idempotencyKey
    ):array {
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $view=$this->optimization->viewRecommendation($organizationId,$recommendationId)
            ?? throw new InvalidArgumentException('Growth optimization recommendation was not found.');

        if(($view['status']??null)===OptimizationRecommendationStatus::Materialized->value){
            return ['recommendation'=>$view,'materialized_target'=>$this->materializedTarget($view),'replayed'=>true];
        }
        if(($view['status']??null)!==OptimizationRecommendationStatus::Accepted->value){
            throw new InvalidArgumentException('Growth optimization recommendation must be accepted before materialization.');
        }

        $targetType=OptimizationTargetType::tryFrom((string)$view['target_type'])
            ?? throw new InvalidArgumentException('Stored Growth optimization target type is invalid.');
        $targetId=(string)$view['target_id'];
        $baseRevision=(int)$view['base_revision'];
        $proposedName=(string)$view['proposed_name'];
        $criteria=$view['proposed_criteria']??null;
        if(!is_array($criteria)||array_is_list($criteria))throw new InvalidArgumentException('Stored Growth optimization criteria are invalid.');

        $nextRevision=$baseRevision+1;
        $existingNext=$this->targetView($organizationId,$targetType,$targetId,$nextRevision);
        if($existingNext!==null){
            $existingName=(string)($existingNext['name']??'');
            $existingCriteria=$existingNext['criteria']??null;
            if(
                $existingName===$proposedName
                &&is_array($existingCriteria)
                &&!array_is_list($existingCriteria)
                &&$this->fingerprint($existingCriteria)===$this->fingerprint($criteria)
            ){
                return $this->finalizeMaterialized(
                    $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,$nextRevision,$existingNext,true,
                );
            }
            return $this->markStale(
                $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,
                'Optimization target revision '. $nextRevision .' already exists with different content.',
            );
        }

        $base=$this->targetView($organizationId,$targetType,$targetId,$baseRevision);
        if($base===null||($base['status']??null)!=='active'){
            return $this->markStale(
                $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,
                'Optimization base target is no longer active.',
            );
        }

        $nestedKey='growth-opt-materialize-'.$recommendationId;
        try{
            $draft=$targetType===OptimizationTargetType::IcpProfile
                ? $this->intelligence->reviseIcpProfile(
                    $organizationId,$actorId,$correlationId,$targetId,$baseRevision,$nestedKey,
                    ['name'=>$proposedName,'criteria'=>$criteria],
                )
                : $this->decisions->reviseQualificationPolicy(
                    $organizationId,$actorId,$correlationId,$targetId,$baseRevision,$nestedKey,
                    ['name'=>$proposedName,'criteria'=>$criteria],
                );
        }catch(DomainException|InvalidArgumentException $error){
            $message=strtolower($error->getMessage());
            if(str_contains($message,'only active')||str_contains($message,'target revision already exists')){
                return $this->markStale(
                    $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,
                    'Optimization base target changed before materialization: '.$error->getMessage(),
                );
            }
            throw $error;
        }

        return $this->finalizeMaterialized(
            $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,$nextRevision,$draft,false,
        );
    }

    public function optimizationBrief(string $organizationId):array
    {
        return [
            'context'=>$this->optimization->optimizationContext($organizationId,200),
            'latest_recommendation'=>$this->optimization->latestRecommendation($organizationId),
        ];
    }

    /** @param array<string,mixed> $materializedTarget @return array<string,mixed> */
    private function finalizeMaterialized(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $idempotencyKey,
        int $revision,array $materializedTarget,bool $recovered
    ):array {
        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,$revision,$materializedTarget,$recovered
        ):array{
            $recommendation=$this->optimization->lockRecommendation($organizationId,$recommendationId);
            if($recommendation->status()===OptimizationRecommendationStatus::Materialized){
                return [
                    'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId),
                    'materialized_target'=>$materializedTarget,
                    'replayed'=>true,
                ];
            }
            if($recommendation->status()!==OptimizationRecommendationStatus::Accepted){
                throw new InvalidArgumentException('Growth optimization recommendation changed state before materialization.');
            }
            $recommendation->materialize(
                $revision,
                $recovered
                    ? 'Recovered existing matching draft revision after interrupted materialization.'
                    : 'Accepted optimization materialized as a draft revision.',
            );
            $this->optimization->updateRecommendation($recommendation,$actorId);
            $this->publish(GrowthEventType::OPTIMIZATION_RECOMMENDATION_MATERIALIZED,$organizationId,'growth_optimization',$recommendationId,[
                'target_type'=>$recommendation->targetType->value,'target_id'=>$recommendation->targetId,
                'base_revision'=>$recommendation->baseRevision,'materialized_revision'=>$revision,'recovered'=>$recovered,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.optimization.materialized','growth_optimization',$recommendationId,
                $idempotencyKey,['materialized_revision'=>$revision,'recovered'=>$recovered],
            );
            return [
                'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Materialized Growth optimization recommendation could not be read back.'),
                'materialized_target'=>$materializedTarget,
                'recovered'=>$recovered,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function decide(
        bool $accept,string $organizationId,int $actorId,string $correlationId,string $recommendationId,
        string $reason,string $idempotencyKey
    ):array {
        $recommendationId=$this->bounded(trim($recommendationId),'recommendationId',80);
        $reason=$this->bounded(trim($reason),'reason',2000);
        $idempotencyKey=$this->bounded(trim($idempotencyKey),'idempotencyKey',191);
        $operation=$accept?'accept_learning_optimization':'dismiss_learning_optimization';
        $fingerprint=$this->fingerprint([
            'recommendation_id'=>$recommendationId,'reason'=>$reason,'decision'=>$accept?'accept':'dismiss',
        ]);

        return $this->transactions->transactional(function()use(
            $accept,$organizationId,$actorId,$correlationId,$recommendationId,$reason,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return [
                    'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId)
                        ?? throw new InvalidArgumentException('Growth optimization decision replay recommendation was not found.'),
                    'replayed'=>true,
                ];
            }
            $recommendation=$this->optimization->lockRecommendation($organizationId,$recommendationId);
            if($accept)$recommendation->accept($reason); else $recommendation->dismiss($reason);
            $this->optimization->updateRecommendation($recommendation,$actorId);
            $event=$accept?GrowthEventType::OPTIMIZATION_RECOMMENDATION_ACCEPTED:GrowthEventType::OPTIMIZATION_RECOMMENDATION_DISMISSED;
            $action=$accept?'growth.optimization.accepted':'growth.optimization.dismissed';
            $this->publish($event,$organizationId,'growth_optimization',$recommendationId,[
                'target_type'=>$recommendation->targetType->value,'target_id'=>$recommendation->targetId,
                'base_revision'=>$recommendation->baseRevision,'reason'=>$reason,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$action,'growth_optimization',$recommendationId,$idempotencyKey,['reason'=>$reason],
            );
            return [
                'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Decided Growth optimization recommendation could not be read back.'),
            ];
        });
    }

    /** @param array<string,mixed> $context */
    private function validateDraft(LearningOptimizationDraft $draft,array $context):void
    {
        if(mb_strlen($draft->proposedName)>191)throw new InvalidArgumentException('Growth optimization proposed name is too long.');
        $targets=$context['active_targets']??null;
        if(!is_array($targets))throw new InvalidArgumentException('Growth optimization context has no active targets.');

        $matched=null;
        $groups=$draft->targetType===OptimizationTargetType::IcpProfile
            ? ($targets['icp_profiles']??[])
            : ($targets['qualification_policies']??[]);
        if(!is_array($groups))throw new InvalidArgumentException('Growth optimization target list is invalid.');
        foreach($groups as $target){
            if(!is_array($target))continue;
            if(
                ($target['target_id']??null)===$draft->targetId
                &&(int)($target['revision']??0)===$draft->baseRevision
            ){
                $matched=$target;
                break;
            }
        }
        if($matched===null)throw new InvalidArgumentException('Growth optimization LLM selected target outside active target set.');

        $current=$matched['criteria']??null;
        if(!is_array($current)||array_is_list($current))throw new InvalidArgumentException('Growth optimization active target criteria are invalid.');
        if($this->fingerprint($current)===$this->fingerprint($draft->proposedCriteria)){
            throw new InvalidArgumentException('Growth optimization LLM proposed a no-op criteria revision.');
        }

        $allowed=array_fill_keys(array_values(array_filter($context['allowed_evidence_ids']??[],'is_string')),true);
        foreach($draft->evidenceIds as $evidenceId){
            if(!isset($allowed[$evidenceId])){
                throw new InvalidArgumentException('Growth optimization LLM cited evidence outside deterministic learning context: '.$evidenceId);
            }
        }
    }

    private function markStale(
        string $organizationId,int $actorId,string $correlationId,string $recommendationId,string $idempotencyKey,string $reason
    ):array {
        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$recommendationId,$idempotencyKey,$reason
        ):array{
            $recommendation=$this->optimization->lockRecommendation($organizationId,$recommendationId);
            if($recommendation->status()===OptimizationRecommendationStatus::Stale){
                return [
                    'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId),
                    'replayed'=>true,
                ];
            }
            $recommendation->markStale($reason);
            $this->optimization->updateRecommendation($recommendation,$actorId);
            $this->publish(GrowthEventType::OPTIMIZATION_RECOMMENDATION_STALE,$organizationId,'growth_optimization',$recommendationId,[
                'target_type'=>$recommendation->targetType->value,'target_id'=>$recommendation->targetId,
                'base_revision'=>$recommendation->baseRevision,'reason'=>$reason,
            ],$actorId,$correlationId);
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.optimization.stale','growth_optimization',$recommendationId,
                $idempotencyKey,['reason'=>$reason],
            );
            return [
                'recommendation'=>$this->optimization->viewRecommendation($organizationId,$recommendationId)
                    ?? throw new InvalidArgumentException('Stale Growth optimization recommendation could not be read back.'),
            ];
        });
    }

    /** @return array<string,mixed>|null */
    private function targetView(string $organizationId,OptimizationTargetType $type,string $targetId,int $revision):?array
    {
        return $type===OptimizationTargetType::IcpProfile
            ? $this->intelligenceRepository->viewIcpProfile($organizationId,$targetId,$revision)
            : $this->decisionRepository->viewPolicy($organizationId,$targetId,$revision);
    }

    /** @param array<string,mixed> $recommendation @return array<string,mixed>|null */
    private function materializedTarget(array $recommendation):?array
    {
        $type=OptimizationTargetType::tryFrom((string)($recommendation['target_type']??''));
        $revision=(int)($recommendation['materialized_revision']??0);
        if($type===null||$revision<1)return null;
        return $this->targetView(
            (string)$recommendation['organization_id'],$type,(string)$recommendation['target_id'],$revision,
        );
    }

    /** @param array<string,mixed> $targets */
    private function targetCount(array $targets):int
    {
        $total=0;
        foreach(['icp_profiles','qualification_policies'] as $group){
            if(is_array($targets[$group]??null))$total+=count($targets[$group]);
        }
        return $total;
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,
        string $subjectId,string $idempotencyKey,array $data=[]
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.optimization','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value):string{return strtoupper(substr(hash('sha256',$value),0,20));}

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

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
