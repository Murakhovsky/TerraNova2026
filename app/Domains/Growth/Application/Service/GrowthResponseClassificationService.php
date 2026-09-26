<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\AI\GrowthResponseClassificationPrompt;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementResponseRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthResponseClassificationBoundary;
use Domains\Growth\Application\Contract\GrowthResponseClassificationGatewayInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthResponseClassificationService implements GrowthResponseClassificationBoundary
{
    public function __construct(
        private GrowthEngagementResponseRepositoryInterface $responses,
        private GrowthRepositoryInterface $growth,
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthResponseClassificationGatewayInterface $gateway,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function classifyResponse(string $organizationId,string $responseId,string $correlationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $responseId=$this->bounded($responseId,'responseId',80);
        $correlationId=$this->bounded($correlationId,'correlationId',191);

        $existing=$this->responses->classificationForVersion(
            $organizationId,$responseId,
            GrowthResponseClassificationPrompt::PROMPT_VERSION,
            GrowthResponseClassificationPrompt::SCHEMA_VERSION,
        );
        if($existing!==null)return $existing+['replayed'=>true];

        $response=$this->responses->byId($organizationId,$responseId)
            ??throw new InvalidArgumentException('Growth engagement response was not found.');
        $context=$this->context($organizationId,$response);
        $draft=$this->gateway->classify($organizationId,$correlationId,$context);

        return $this->transactions->transactional(function()use($organizationId,$responseId,$correlationId,$draft):array{
            $response=$this->responses->lockById($organizationId,$responseId);
            $existing=$this->responses->classificationForVersion(
                $organizationId,$responseId,$draft->promptVersion,$draft->schemaVersion,
            );
            if($existing!==null)return $existing+['replayed'=>true];

            $latest=$this->responses->latestClassification($organizationId,$responseId);
            $revision=(int)($latest['revision']??0)+1;
            $classificationId='GERC-'.strtoupper(substr(hash(
                'sha256',$organizationId.':'.$responseId.':'.$draft->promptVersion.':'.$draft->schemaVersion
            ),0,20));
            $classification=[
                'organization_id'=>$organizationId,'classification_id'=>$classificationId,'response_id'=>$responseId,
                'revision'=>$revision,'intent'=>$draft->intent->value,'sentiment'=>$draft->sentiment->value,
                'urgency'=>$draft->urgency->value,'summary'=>$draft->summary,'requested_action'=>$draft->requestedAction,
                'recommended_next_owner'=>$draft->recommendedNextOwner->value,'confidence'=>$draft->confidence,
                'provider'=>$draft->provider,'model'=>$draft->model,'prompt_version'=>$draft->promptVersion,
                'schema_version'=>$draft->schemaVersion,'input_tokens'=>$draft->inputTokens,'output_tokens'=>$draft->outputTokens,
                'cost_amount'=>$draft->costAmount,'cost_currency'=>$draft->costCurrency,'created_at'=>$this->now()->format(DATE_ATOM),
            ];
            $this->responses->appendClassification($classification);

            $candidateId=(string)$response['candidate_id'];
            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_RESPONSE_CLASSIFIED,
                'growth_candidate',$candidateId,[
                    'classification_id'=>$classificationId,'response_id'=>$responseId,'revision'=>$revision,
                    'intent'=>$draft->intent->value,'sentiment'=>$draft->sentiment->value,'urgency'=>$draft->urgency->value,
                    'recommended_next_owner'=>$draft->recommendedNextOwner->value,'confidence'=>$draft->confidence,
                    'prompt_version'=>$draft->promptVersion,'schema_version'=>$draft->schemaVersion,
                ],
                new EventMetadata($correlationId,null,'SYSTEM','growth-response-classifier'),$this->now(),
            ));
            $this->audit->append(new AuditEntry(
                bin2hex(random_bytes(16)),$organizationId,'growth.engagement_response','SYSTEM','growth-response-classifier',
                'growth_candidate',$candidateId,null,[
                    'action'=>'growth.engagement.response_classified',
                    'input_references'=>['response_id'=>$responseId],
                    'result'=>[
                        'classification_id'=>$classificationId,'revision'=>$revision,
                        'intent'=>$draft->intent->value,'sentiment'=>$draft->sentiment->value,
                        'urgency'=>$draft->urgency->value,'recommended_next_owner'=>$draft->recommendedNextOwner->value,
                        'confidence'=>$draft->confidence,'prompt_version'=>$draft->promptVersion,'schema_version'=>$draft->schemaVersion,
                    ],
                ],$correlationId,$this->now(),
            ));

            return $classification+['replayed'=>false];
        });
    }

    private function context(string $organizationId,array $response):array
    {
        $recommendation=$this->engagement->viewRecommendation($organizationId,(string)$response['recommendation_id']);
        $candidate=$this->growth->viewCandidate($organizationId,(string)$response['candidate_id']);

        return [
            'response'=>[
                'channel'=>$response['channel'],
                'body'=>$response['body'],
                'occurred_at'=>$response['occurred_at'],
            ],
            'prior_outreach'=>is_array($recommendation)?[
                'action_type'=>$recommendation['action_type']??null,
                'message_angle'=>$recommendation['message_angle']??null,
            ]:null,
            'candidate_context'=>is_array($candidate)?[
                'opportunity_type'=>$candidate['opportunity_type']??null,
                'growth_mode'=>$candidate['growth_mode']??null,
                'target_domain'=>$candidate['target_domain']??null,
            ]:null,
        ];
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
