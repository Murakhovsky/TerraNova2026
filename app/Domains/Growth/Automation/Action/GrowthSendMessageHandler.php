<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthOutboundMessageGatewayInterface;
use InvalidArgumentException;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Execution\ExecutionFailureKind;
use Throwable;

final readonly class GrowthSendMessageHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='growth.send_message';

    public function __construct(
        private GrowthBuyingCommitteeRepositoryInterface $contacts,
        private GrowthOutboundMessageGatewayInterface $outbound,
    ) {}

    public function supports(string $actionType):bool{return $actionType===self::TYPE;}

    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        if($action->targetType!=='growth_contact'||$action->targetId===null){
            return ExecutionResult::failure('Growth contact target is required.');
        }

        $channel=strtolower(trim((string)($action->parameters['channel']??'')));
        if($channel!=='email'){
            return ExecutionResult::failure('Growth pre-handoff execution currently supports email only.');
        }
        $body=trim((string)($action->parameters['body']??''));
        if($body===''||mb_strlen($body)>10000){
            return ExecutionResult::failure('Growth outbound message body must be 1..10000 characters.');
        }
        $subject=trim((string)($action->parameters['subject']??''));
        if(mb_strlen($subject)>250){
            return ExecutionResult::failure('Growth outbound email subject is too long.');
        }
        $locale=trim((string)($action->parameters['locale']??'en'));
        if($locale===''||mb_strlen($locale)>20){
            return ExecutionResult::failure('Growth outbound locale is invalid.');
        }

        $contact=$this->contacts->viewContact($action->organizationId,$action->targetId);
        if($contact===null)return ExecutionResult::failure('Growth contact was not found.');
        $identityType=strtolower(trim((string)($contact['identity_type']??'')));
        $identityValue=trim((string)($contact['identity_value']??''));
        if($identityType!=='email'||filter_var($identityValue,FILTER_VALIDATE_EMAIL)===false){
            return ExecutionResult::failure('Growth contact has no valid email identity.');
        }

        try{
            $delivery=$this->outbound->queueEmail(
                $action->organizationId,
                $identityValue,
                trim((string)($contact['full_name']??''))?:null,
                $body,
                $subject!==''?$subject:null,
                $locale,
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
                'Growth outbound notification unavailable: '.$error->getMessage(),
                [],
                [],
                ExecutionFailureKind::ExternalUnavailable,
            );
        }

        if($delivery->status==='failed'){
            return ExecutionResult::failure(
                'Growth outbound integration delivery is currently failed.',
                [
                    'notification_id'=>$delivery->notificationId,
                    'delivery_id'=>$delivery->deliveryId,
                    'provider_message_id'=>$delivery->providerMessageId,
                ],
                [],
                ExecutionFailureKind::ExternalUnavailable,
            );
        }

        return ExecutionResult::success([
            'notification_id'=>$delivery->notificationId,
            'delivery_id'=>$delivery->deliveryId,
            'delivery_status'=>$delivery->status,
            'provider_message_id'=>$delivery->providerMessageId,
        ],['notifications_queued'=>1]);
    }
}
