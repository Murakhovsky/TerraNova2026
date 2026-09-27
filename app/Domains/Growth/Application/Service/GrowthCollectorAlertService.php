<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthCollectorAlertBoundary;
use Domains\Growth\Application\Contract\GrowthCollectorAlertSubscriptionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthCollectorAlertSubscription;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthCollectorAlertService implements GrowthCollectorAlertBoundary
{
    public function __construct(
        private GrowthCollectorAlertSubscriptionRepositoryInterface $subscriptions,
        private GrowthMutationReceiptInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createSubscription(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        if($actorId<1)throw new InvalidArgumentException('Growth collector alert actor id must be positive.');

        $email=mb_strtolower(trim((string)($input['recipient_email']??'')));
        $name=trim((string)($input['recipient_name']??''));
        $name=$name===''?null:$name;
        $locale=str_replace('_','-',trim((string)($input['locale']??'en')));
        $enabled=array_key_exists('enabled',$input)?$input['enabled']:true;
        if(!is_bool($enabled))throw new InvalidArgumentException('Growth collector alert enabled must be boolean.');

        $candidate=new GrowthCollectorAlertSubscription(
            'GCAS-'.$this->stableId($organizationId.':'.$email),
            OrganizationId::fromString($organizationId),
            $email,$name,$locale,$enabled,
        );
        $fingerprint=$this->fingerprint([
            'recipient_email'=>$candidate->recipientEmail,
            'recipient_name'=>$candidate->recipientName,
            'locale'=>$candidate->locale,
            'enabled'=>$candidate->enabled(),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$candidate,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_collector_alert_subscription',$idempotencyKey,$fingerprint)){
                return ($this->subscriptions->view($organizationId,$candidate->id)
                    ?? throw new InvalidArgumentException('Growth collector alert receipt exists but subscription was not found.'))
                    + ['replayed'=>true];
            }

            $existing=$this->subscriptions->findByEmail($organizationId,$candidate->recipientEmail);
            if($existing!==null)return $existing+['existing'=>true];

            $this->subscriptions->create($candidate,$actorId);
            $this->publish(
                GrowthEventType::COLLECTOR_ALERT_SUBSCRIPTION_CREATED,$organizationId,'growth_collector_alert_subscription',
                $candidate->id,['enabled'=>$candidate->enabled(),'locale'=>$candidate->locale],$actorId,$correlationId,
            );
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,'growth.collector_alert.create',
                $candidate->id,$idempotencyKey,['recipient_email_hash'=>hash('sha256',$candidate->recipientEmail)],
            );
            return $this->subscriptions->view($organizationId,$candidate->id)
                ?? throw new InvalidArgumentException('Created Growth collector alert subscription could not be read back.');
        });
    }

    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $subscriptionId,bool $enabled,string $idempotencyKey
    ):array {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        $subscriptionId=$this->bounded($subscriptionId,'subscriptionId',80);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        if($actorId<1)throw new InvalidArgumentException('Growth collector alert actor id must be positive.');
        $operation=$enabled?'enable_collector_alert_subscription':'disable_collector_alert_subscription';
        $fingerprint=$this->fingerprint(['subscription_id'=>$subscriptionId,'enabled'=>$enabled]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$subscriptionId,$enabled,$idempotencyKey,$operation,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return ($this->subscriptions->view($organizationId,$subscriptionId)
                    ?? throw new InvalidArgumentException('Growth collector alert toggle receipt exists but subscription was not found.'))
                    + ['replayed'=>true];
            }

            $subscription=$this->subscriptions->lock($organizationId,$subscriptionId);
            if($enabled)$subscription->enable();else $subscription->disable();
            $this->subscriptions->update($subscription,$actorId);

            $event=$enabled?GrowthEventType::COLLECTOR_ALERT_SUBSCRIPTION_ENABLED:GrowthEventType::COLLECTOR_ALERT_SUBSCRIPTION_DISABLED;
            $this->publish(
                $event,$organizationId,'growth_collector_alert_subscription',$subscriptionId,
                ['enabled'=>$enabled],$actorId,$correlationId,
            );
            $this->appendAudit(
                $organizationId,$actorId,$correlationId,$enabled?'growth.collector_alert.enable':'growth.collector_alert.disable',
                $subscriptionId,$idempotencyKey,['enabled'=>$enabled],
            );
            return $this->subscriptions->view($organizationId,$subscriptionId)
                ?? throw new InvalidArgumentException('Updated Growth collector alert subscription could not be read back.');
        });
    }

    public function subscriptions(string $organizationId):array
    {
        return $this->subscriptions->listAll($this->bounded($organizationId,'organizationId',64),100);
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
        string $organizationId,int $actorId,string $correlationId,string $action,
        string $subscriptionId,string $idempotencyKey,array $data
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.collector_alert','USER',(string)$actorId,
            'growth_collector_alert_subscription',$subscriptionId,null,
            ['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function stableId(string $value):string{return strtoupper(substr(hash('sha256',$value),0,20));}

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        ksort($value,SORT_STRING);
        return hash('sha256',(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now():DateTimeImmutable{return new DateTimeImmutable('now',new DateTimeZone('UTC'));}
}
