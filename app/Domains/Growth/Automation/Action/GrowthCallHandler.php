<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthExternalEngagementGatewayInterface;
use InvalidArgumentException;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Execution\ExecutionFailureKind;
use Throwable;

final readonly class GrowthCallHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='growth.place_call';

    public function __construct(
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private GrowthExternalEngagementGatewayInterface $outbound,
    ) {}

    public function supports(string $actionType):bool{return $actionType===self::TYPE;}

    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        if($action->targetType!=='growth_contact'||$action->targetId===null){
            return ExecutionResult::failure('Growth contact target is required.');
        }
        if(strtolower(trim((string)($action->parameters['channel']??'')))!=='phone'){
            return ExecutionResult::failure('Growth call action requires phone channel.');
        }
        $brief=trim((string)($action->parameters['body']??''));
        if($brief===''||mb_strlen($brief)>10000){
            return ExecutionResult::failure('Growth call brief must be 1..10000 characters.');
        }

        $contact=$this->contacts->viewContact($action->organizationId,$action->targetId);
        if($contact===null)return ExecutionResult::failure('Growth contact was not found.');
        if(strtolower(trim((string)($contact['identity_type']??'')))!=='phone'){
            return ExecutionResult::failure('Growth contact has no phone identity.');
        }
        $phone=trim((string)($contact['identity_value']??''));
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$phone)){
            return ExecutionResult::failure('Growth contact phone identity must use E.164 format.');
        }

        try{
            $delivery=$this->outbound->queueCall(
                $action->organizationId,
                $phone,
                trim((string)($contact['full_name']??''))?:null,
                $brief,
                $action->correlationId!==''?$action->correlationId:$action->id,
                $this->idempotencyKey($action),
                [
                    'growth_contact_id'=>$action->targetId,
                    'growth_candidate_id'=>$action->parameters['growth_candidate_id']??null,
                    'growth_recommendation_id'=>$action->parameters['growth_recommendation_id']??null,
                    'kernel_action_id'=>$action->id,
                ],
            );
        }catch(InvalidArgumentException $error){
            return ExecutionResult::failure($error->getMessage());
        }catch(Throwable $error){
            return ExecutionResult::failure(
                'Growth call integration unavailable: '.$error->getMessage(),
                [],[],ExecutionFailureKind::ExternalUnavailable,
            );
        }

        if($delivery->status==='failed'){
            return ExecutionResult::failure(
                'Growth call delivery is currently failed.',
                ['delivery_id'=>$delivery->deliveryId,'provider_reference'=>$delivery->providerReference],
                [],ExecutionFailureKind::ExternalUnavailable,
            );
        }
        return ExecutionResult::success([
            'delivery_id'=>$delivery->deliveryId,
            'delivery_status'=>$delivery->status,
            'provider_reference'=>$delivery->providerReference,
        ],['calls_queued'=>1]);
    }
}
