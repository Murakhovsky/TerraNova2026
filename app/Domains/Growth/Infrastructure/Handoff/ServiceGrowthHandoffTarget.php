<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Handoff;

use Domains\Growth\Application\Contract\GrowthHandoffTargetInterface;
use Domains\Growth\Application\DTO\HandoffTargetResult;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use RuntimeException;

final readonly class ServiceGrowthHandoffTarget implements GrowthHandoffTargetInterface
{
    public function __construct(
        private ServiceApplicationBoundary $service,
        private ActiveModuleResolver $modules,
    ) {}

    public function domain(): string
    {
        return 'service';
    }

    public function accept(
        OpportunityHandoff $handoff,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
    ): HandoffTargetResult {
        if($handoff->targetDomain!==$this->domain()){
            throw new InvalidArgumentException('Service Growth handoff target received a package for another Domain.');
        }
        if(!$this->modules->isEnabled($handoff->organizationId,'service')){
            return HandoffTargetResult::rejected('Service module is disabled for this organization.');
        }

        $subject=$this->bounded(
            $this->label($handoff->opportunityType).' — '.$handoff->whyNow,
            220,
        );
        $summary=$this->bounded(implode("\n\n",[
            $handoff->whyItMatters,
            'Problem hypothesis: '.$handoff->problemHypothesis,
            'Expected value: '.$handoff->expectedValue,
            'Recommended play: '.$handoff->recommendedPlay,
            'Recommended action: '.$handoff->recommendedAction,
            'Growth candidate: '.$handoff->candidateId,
        ]),500);
        $requesterRef=$this->bounded(
            'growth:'.$handoff->subjectType.':'.$handoff->subjectId,
            191,
        );

        $request=$this->service->createRequest(
            $handoff->organizationId,
            $actorId,
            $correlationId,
            $idempotencyKey,
            [
                'subject'=>$subject,
                'summary'=>$summary,
                'requester_ref'=>$requesterRef,
            ],
        );

        $requestId=$request['request_id']??null;
        if(!is_string($requestId)||trim($requestId)===''){
            throw new RuntimeException('Service accepted Growth handoff but did not return request_id.');
        }

        return HandoffTargetResult::accepted(
            'service_request',
            trim($requestId),
            'Service accepted Growth opportunity as a Service Request.',
        );
    }

    private function label(string $opportunityType): string
    {
        $label=str_replace(['_','-'],' ',trim($opportunityType));
        $label=preg_replace('/\s+/',' ',$label)?:'Growth opportunity';
        return mb_convert_case($label,MB_CASE_TITLE,'UTF-8');
    }

    private function bounded(string $value,int $limit): string
    {
        $value=trim($value);
        if($value==='')throw new InvalidArgumentException('Service Growth handoff mapping produced an empty value.');
        return mb_strlen($value)<=$limit?$value:rtrim(mb_substr($value,0,$limit-1)).'…';
    }
}
