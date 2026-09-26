<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthConversationRoutingBoundary;
use Domains\Growth\Application\Contract\GrowthConversationRoutingRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementResponseRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\GrowthConversationRouteRequest;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthConversationRoute;
use Domains\Growth\Domain\GrowthConversationRoutingPolicy;
use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthConversationRoutingService implements GrowthConversationRoutingBoundary
{
    private const SYSTEM_ACTOR_ID=0;

    public function __construct(
        private GrowthEngagementResponseRepositoryInterface $responses,
        private GrowthRepositoryInterface $growth,
        private GrowthEngagementRepositoryInterface $engagement,
        private GrowthConversationRoutingRepositoryInterface $repository,
        private GrowthConversationRoutingTargetRegistry $targets,
        private GrowthConversationRoutingPolicy $policy,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function routeResponse(string $organizationId,string $responseId,string $correlationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $responseId=$this->bounded($responseId,'responseId',80);
        $correlationId=$this->bounded($correlationId,'correlationId',191);

        $route=$this->repository->routeForResponse($organizationId,$responseId);
        if($route!==null&&in_array((string)$route['status'],['completed','queued','rejected'],true)){
            return $route+['replayed'=>true];
        }

        $classification=$this->responses->latestClassification($organizationId,$responseId)
            ??throw new InvalidArgumentException('Growth response must be classified before routing.');
        $classificationId=(string)$classification['classification_id'];

        $route??=$this->repository->routeForClassification($organizationId,$classificationId);
        if($route===null){
            $route=$this->transactions->transactional(function()use($organizationId,$responseId,$correlationId):array{
                $response=$this->responses->lockById($organizationId,$responseId);
                $classification=$this->responses->latestClassification($organizationId,$responseId)
                    ??throw new InvalidArgumentException('Growth response classification disappeared during routing.');
                $classificationId=(string)$classification['classification_id'];
                $existing=$this->repository->routeForResponse($organizationId,$responseId)
                    ??$this->repository->routeForClassification($organizationId,$classificationId);
                if($existing!==null)return $existing;

                $recommendation=$this->engagement->viewRecommendation(
                    $organizationId,(string)$response['recommendation_id']
                )??throw new InvalidArgumentException('Growth response recommendation was not found for routing.');
                $candidate=$this->growth->viewCandidate(
                    $organizationId,(string)$response['candidate_id']
                )??throw new InvalidArgumentException('Growth response Candidate was not found for routing.');

                $intent=GrowthResponseIntent::tryFrom((string)$classification['intent'])
                    ??throw new InvalidArgumentException('Stored Growth response intent is invalid.');
                $owner=GrowthResponseNextOwner::tryFrom((string)$classification['recommended_next_owner'])
                    ??throw new InvalidArgumentException('Stored Growth response owner recommendation is invalid.');
                $contactId=$this->nullable((string)($recommendation['contact_id']??''));
                $decision=$this->policy->decide($intent,$owner,(float)$classification['confidence'],$contactId!==null);

                $routeId='GCR-'.strtoupper(substr(hash('sha256',$organizationId.':'.$classificationId),0,20));
                $now=$this->now()->format(DATE_ATOM);
                $route=[
                    'organization_id'=>$organizationId,'route_id'=>$routeId,'response_id'=>$responseId,
                    'classification_id'=>$classificationId,'candidate_id'=>(string)$response['candidate_id'],
                    'recommendation_id'=>(string)$response['recommendation_id'],'contact_id'=>$contactId,
                    'route'=>$decision['route']->value,'policy_version'=>GrowthConversationRoutingPolicy::POLICY_VERSION,
                    'decision_reason'=>$decision['reason'],'status'=>'pending',
                    'target_reference_type'=>null,'target_reference_id'=>null,'error_summary'=>null,
                    'created_at'=>$now,'updated_at'=>$now,
                ];
                $this->repository->appendRoute($route);

                $this->events->publish(new DomainEvent(
                    bin2hex(random_bytes(16)),$organizationId,GrowthEventType::ENGAGEMENT_RESPONSE_ROUTE_DECIDED,
                    'growth_candidate',(string)$response['candidate_id'],[
                        'route_id'=>$routeId,'response_id'=>$responseId,'classification_id'=>$classificationId,
                        'route'=>$decision['route']->value,'policy_version'=>GrowthConversationRoutingPolicy::POLICY_VERSION,
                    ],
                    new EventMetadata($correlationId,null,'SYSTEM','growth-conversation-router'),$this->now(),
                ));
                $this->audit->append(new AuditEntry(
                    bin2hex(random_bytes(16)),$organizationId,'growth.conversation_routing','SYSTEM','growth-conversation-router',
                    'growth_candidate',(string)$response['candidate_id'],null,[
                        'action'=>'growth.engagement.response_route_decided',
                        'input_references'=>['response_id'=>$responseId,'classification_id'=>$classificationId],
                        'result'=>[
                            'route_id'=>$routeId,'route'=>$decision['route']->value,
                            'policy_version'=>GrowthConversationRoutingPolicy::POLICY_VERSION,
                            'reason'=>$decision['reason'],
                        ],
                    ],$correlationId,$this->now(),
                ));
                return $route;
            });
        }

        if(in_array((string)$route['status'],['completed','queued','rejected'],true)){
            return $route+['replayed'=>true];
        }

        return $this->execute($organizationId,$route,$correlationId);
    }

    public function routingBrief(string $organizationId,string $candidateId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $candidateId=$this->bounded($candidateId,'candidateId',80);
        $routes=$this->repository->latestForCandidate($organizationId,$candidateId,20);
        return [
            'candidate_id'=>$candidateId,
            'routes'=>$routes,
            'count'=>count($routes),
            'registered_targets'=>$this->targets->routes(),
            'policy'=>[
                'version'=>GrowthConversationRoutingPolicy::POLICY_VERSION,
                'min_confidence'=>GrowthConversationRoutingPolicy::MIN_CONFIDENCE,
            ],
        ];
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    private function execute(string $organizationId,array $route,string $correlationId):array
    {
        $routeEnum=GrowthConversationRoute::tryFrom((string)$route['route'])
            ??throw new InvalidArgumentException('Stored Growth conversation route is invalid.');

        if($routeEnum===GrowthConversationRoute::Suppression){
            $contactId=$this->nullable((string)($route['contact_id']??''));
            if($contactId===null){
                return $this->finalize($organizationId,$route,$correlationId,'queued',null,null,
                    'Suppression requires human review because no contact is attached.');
            }
            return $this->transactions->transactional(function()use($organizationId,$route,$correlationId,$contactId):array{
                $this->repository->suppressContact(
                    $organizationId,$contactId,(string)$route['response_id'],'Explicit unsubscribe response.'
                );
                return $this->finalizeInsideTransaction(
                    $organizationId,$route,$correlationId,'completed','growth_contact',$contactId,
                    'Contact suppressed from future Growth outreach.'
                );
            });
        }

        if($routeEnum===GrowthConversationRoute::NoAction){
            return $this->finalize($organizationId,$route,$correlationId,'completed',null,null,
                'No follow-up action is required by deterministic conversation policy.');
        }

        if(in_array($routeEnum,[
            GrowthConversationRoute::Growth,
            GrowthConversationRoute::Partnership,
            GrowthConversationRoute::HumanReview,
        ],true)){
            return $this->finalize($organizationId,$route,$correlationId,'queued',null,null,
                'Conversation is queued for '.$routeEnum->value.' ownership.');
        }

        $request=$this->request($organizationId,$route);
        $target=$this->targets->get($routeEnum->value);
        $idempotencyKey='growth-conversation-route:'.$route['route_id'];

        try{
            $result=$target->accept($request,self::SYSTEM_ACTOR_ID,$correlationId,$idempotencyKey);
        }catch(Throwable $exception){
            $summary=$this->errorSummary($exception);
            $this->finalize($organizationId,$route,$correlationId,'failed',null,null,$summary,true);
            throw $exception;
        }

        if(!$result->accepted){
            return $this->finalize(
                $organizationId,$route,$correlationId,'rejected',null,null,$result->reason
            );
        }

        return $this->finalize(
            $organizationId,$route,$correlationId,'completed',
            $result->referenceType,$result->referenceId,$result->reason
        );
    }

    /** @param array<string,mixed> $route */
    private function request(string $organizationId,array $route):GrowthConversationRouteRequest
    {
        $classification=$this->responses->latestClassification($organizationId,(string)$route['response_id'])
            ??throw new InvalidArgumentException('Growth routing classification was not found.');
        $candidate=$this->growth->viewCandidate($organizationId,(string)$route['candidate_id'])
            ??throw new InvalidArgumentException('Growth routing Candidate was not found.');

        return new GrowthConversationRouteRequest(
            $organizationId,(string)$route['route_id'],(string)$route['response_id'],(string)$route['classification_id'],
            (string)$route['candidate_id'],(string)$route['recommendation_id'],$this->nullable((string)($route['contact_id']??'')),
            (string)$classification['intent'],(string)$classification['urgency'],(string)$classification['summary'],
            (string)($classification['requested_action']??''),$this->nullable((string)($candidate['target_domain']??'')),
        );
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    private function finalize(
        string $organizationId,array $route,string $correlationId,string $status,
        ?string $referenceType,?string $referenceId,string $reason,bool $failure=false
    ):array {
        return $this->transactions->transactional(
            fn():array=>$this->finalizeInsideTransaction(
                $organizationId,$route,$correlationId,$status,$referenceType,$referenceId,$reason,$failure
            )
        );
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    private function finalizeInsideTransaction(
        string $organizationId,array $route,string $correlationId,string $status,
        ?string $referenceType,?string $referenceId,string $reason,bool $failure=false
    ):array {
        $this->repository->updateRouteStatus(
            $organizationId,(string)$route['route_id'],$status,$referenceType,$referenceId,$failure?$reason:null
        );
        $eventType=$failure?GrowthEventType::ENGAGEMENT_RESPONSE_ROUTE_FAILED:GrowthEventType::ENGAGEMENT_RESPONSE_ROUTED;
        $payload=[
            'route_id'=>$route['route_id'],'response_id'=>$route['response_id'],'classification_id'=>$route['classification_id'],
            'route'=>$route['route'],'status'=>$status,'target_reference_type'=>$referenceType,'target_reference_id'=>$referenceId,
        ];
        if($failure)$payload['error_summary']=$reason;

        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$eventType,'growth_candidate',(string)$route['candidate_id'],$payload,
            new EventMetadata($correlationId,null,'SYSTEM','growth-conversation-router'),$this->now(),
        ));
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.conversation_routing','SYSTEM','growth-conversation-router',
            'growth_candidate',(string)$route['candidate_id'],null,[
                'action'=>$failure?'growth.engagement.response_route_failed':'growth.engagement.response_routed',
                'input_references'=>[
                    'route_id'=>$route['route_id'],'response_id'=>$route['response_id'],
                    'classification_id'=>$route['classification_id'],
                ],
                'result'=>[
                    'route'=>$route['route'],'status'=>$status,'target_reference_type'=>$referenceType,
                    'target_reference_id'=>$referenceId,'reason'=>$reason,
                ],
            ],$correlationId,$this->now(),
        ));

        return $this->repository->routeForClassification($organizationId,(string)$route['classification_id'])
            ??throw new InvalidArgumentException('Growth conversation route disappeared after execution.');
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function nullable(string $value):?string
    {
        $value=trim($value);return $value===''?null:$value;
    }

    private function errorSummary(Throwable $exception):string
    {
        $summary=trim($exception->getMessage());
        if($summary==='')$summary=get_class($exception);
        return mb_strlen($summary)<=1000?$summary:rtrim(mb_substr($summary,0,999)).'…';
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
