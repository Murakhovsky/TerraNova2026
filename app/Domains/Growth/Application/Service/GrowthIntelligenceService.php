<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthIntelligenceBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Domain\AccountSnapshot;
use Domains\Growth\Domain\GrowthAccount;
use Domains\Growth\Domain\IcpCriteria;
use Domains\Growth\Domain\IcpMatcher;
use Domains\Growth\Domain\IcpProfile;
use Domains\Growth\Domain\IcpProfileStatus;
use Domains\Growth\Automation\Event\GrowthEventType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class GrowthIntelligenceService implements GrowthIntelligenceBoundary
{
    public function __construct(
        private GrowthIntelligenceRepositoryInterface $intelligence,
        private GrowthRepositoryInterface $growth,
        private GrowthMutationReceiptInterface $receipts,
        private IcpMatcher $matcher,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createIcpProfile(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array
    {
        $name=$this->required($input,'name',191);
        $criteriaRaw=$input['criteria']??null;
        if(!is_array($criteriaRaw))throw new InvalidArgumentException('Growth ICP criteria must be an object.');
        $criteria=IcpCriteria::fromArray($criteriaRaw);
        $profileId='GICP-'.$this->stableId($organizationId.':icp:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['name'=>$name,'criteria'=>$criteria->toArray()]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$name,$criteria,$profileId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'create_icp_profile',$idempotencyKey,$fingerprint)){
                return ($this->intelligence->viewIcpProfile($organizationId,$profileId,1)
                    ?? throw new InvalidArgumentException('Growth ICP receipt exists but profile was not found.'))
                    + ['replayed'=>true];
            }
            $profile=IcpProfile::draft($profileId,OrganizationId::fromString($organizationId),$name,$criteria);
            $this->intelligence->createIcpProfile($profile,$actorId);
            $this->publish(GrowthEventType::ICP_DRAFTED,$organizationId,'growth_icp',$profileId,[
                'revision'=>1,'name'=>$name,'criteria'=>$criteria->toArray(),
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.icp.create','growth_icp',$profileId,$idempotencyKey);
            return $this->intelligence->viewIcpProfile($organizationId,$profileId,1)
                ?? throw new InvalidArgumentException('Created Growth ICP could not be read back.');
        });
    }

    public function reviseIcpProfile(
        string $organizationId,int $actorId,string $correlationId,string $profileId,int $baseRevision,string $idempotencyKey,array $input
    ): array {
        $profileId=$this->bounded(trim($profileId),'profileId',80);
        if($baseRevision<1)throw new InvalidArgumentException('Growth ICP base revision must be positive.');
        $name=$this->required($input,'name',191);
        $criteriaRaw=$input['criteria']??null;
        if(!is_array($criteriaRaw))throw new InvalidArgumentException('Growth ICP criteria must be an object.');
        $criteria=IcpCriteria::fromArray($criteriaRaw);
        $fingerprint=$this->fingerprint([
            'profile_id'=>$profileId,'base_revision'=>$baseRevision,'name'=>$name,'criteria'=>$criteria->toArray(),
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$profileId,$baseRevision,$idempotencyKey,$name,$criteria,$fingerprint
        ):array{
            $newRevision=$baseRevision+1;
            if(!$this->receipts->claim($organizationId,'revise_icp_profile',$idempotencyKey,$fingerprint)){
                return ($this->intelligence->viewIcpProfile($organizationId,$profileId,$newRevision)
                    ?? throw new InvalidArgumentException('Growth ICP revision receipt exists but revision was not found.'))
                    + ['replayed'=>true];
            }
            $base=$this->intelligence->lockIcpProfile($organizationId,$profileId,$baseRevision);
            if($this->intelligence->viewIcpProfile($organizationId,$profileId,$newRevision)!==null){
                throw new InvalidArgumentException('Growth ICP target revision already exists.');
            }
            $revision=$base->revise($name,$criteria);
            $this->intelligence->createIcpProfile($revision,$actorId);
            $this->publish(GrowthEventType::ICP_REVISED,$organizationId,'growth_icp',$profileId,[
                'base_revision'=>$baseRevision,'revision'=>$revision->revision,'name'=>$name,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.icp.revise','growth_icp',$profileId,$idempotencyKey,[
                'base_revision'=>$baseRevision,'revision'=>$revision->revision,
            ]);
            return $this->intelligence->viewIcpProfile($organizationId,$profileId,$revision->revision)
                ?? throw new InvalidArgumentException('Created Growth ICP revision could not be read back.');
        });
    }

    public function activateIcpProfile(string $organizationId,int $actorId,string $correlationId,string $profileId,int $revision,string $idempotencyKey): array
    {
        $profileId=$this->bounded(trim($profileId),'profileId',80);
        if($revision<1)throw new InvalidArgumentException('Growth ICP revision must be positive.');
        $fingerprint=$this->fingerprint(['profile_id'=>$profileId,'revision'=>$revision]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$profileId,$revision,$idempotencyKey,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'activate_icp_profile',$idempotencyKey,$fingerprint)){
                return ($this->intelligence->viewIcpProfile($organizationId,$profileId,$revision)
                    ?? throw new InvalidArgumentException('Growth ICP activation receipt exists but profile was not found.'))
                    + ['replayed'=>true];
            }
            $profile=$this->intelligence->lockIcpProfile($organizationId,$profileId,$revision);
            $profile->activate();
            $this->intelligence->archiveOtherActiveIcpRevisions($organizationId,$profileId,$revision,$actorId);
            $this->intelligence->updateIcpProfile($profile,$actorId);
            $this->publish(GrowthEventType::ICP_ACTIVATED,$organizationId,'growth_icp',$profileId,[
                'revision'=>$revision,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.icp.activate','growth_icp',$profileId,$idempotencyKey,[
                'revision'=>$revision,
            ]);
            return $this->intelligence->viewIcpProfile($organizationId,$profileId,$revision)
                ?? throw new InvalidArgumentException('Activated Growth ICP could not be read back.');
        });
    }

    public function discoverAccount(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input): array
    {
        $name=$this->required($input,'name',220);
        $domain=strtolower($this->required($input,'canonical_domain',191));
        if(str_contains($domain,'://')||str_contains($domain,'/'))throw new InvalidArgumentException('canonical_domain must contain a hostname only.');
        $accountId='GACC-'.$this->stableId($organizationId.':account:'.$domain);
        $fingerprint=$this->fingerprint(['name'=>$name,'canonical_domain'=>$domain]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$name,$domain,$accountId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'discover_account',$idempotencyKey,$fingerprint)){
                return ($this->intelligence->viewAccount($organizationId,$accountId)
                    ?? throw new InvalidArgumentException('Growth account receipt exists but account was not found.'))
                    + ['replayed'=>true];
            }
            $existing=$this->intelligence->findAccountByDomain($organizationId,$domain);
            if($existing!==null)return $existing+['existing'=>true];

            $account=new GrowthAccount($accountId,OrganizationId::fromString($organizationId),$name,$domain);
            $this->intelligence->createAccount($account,$actorId);
            $this->publish(GrowthEventType::ACCOUNT_DISCOVERED,$organizationId,'growth_account',$accountId,[
                'name'=>$name,'canonical_domain'=>$domain,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.account.discover','growth_account',$accountId,$idempotencyKey);
            return $this->intelligence->viewAccount($organizationId,$accountId)
                ?? throw new InvalidArgumentException('Discovered Growth account could not be read back.');
        });
    }

    public function captureAccountSnapshot(string $organizationId,int $actorId,string $correlationId,string $accountId,string $idempotencyKey,array $input): array
    {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        if($this->intelligence->viewAccount($organizationId,$accountId)===null)throw new InvalidArgumentException('Growth account was not found.');
        $firmographics=$this->facts($input['firmographics']??[]);
        $technologies=$this->optionalStringList($input['technologies']??[],'technologies');
        $hiring=$this->optionalStringList($input['hiring']??[],'hiring');
        $recentChanges=$this->optionalStringList($input['recent_changes']??[],'recent_changes');
        $signalTypes=$this->optionalStringList($input['signal_types']??[],'signal_types');
        $sourceReferences=$this->stringList($input['source_references']??null,'source_references');
        $observedAt=$this->date($input['observed_at']??null,'observed_at');
        $capturedAt=$this->now();
        $snapshotId='GASN-'.$this->stableId($organizationId.':snapshot:'.$idempotencyKey);
        $payload=[
            'account_id'=>$accountId,'firmographics'=>$firmographics,'technologies'=>$technologies,'hiring'=>$hiring,
            'recent_changes'=>$recentChanges,'signal_types'=>$signalTypes,'source_references'=>$sourceReferences,
            'observed_at'=>$observedAt->format(DATE_ATOM),
        ];
        $fingerprint=$this->fingerprint($payload);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$accountId,$idempotencyKey,$firmographics,$technologies,$hiring,
            $recentChanges,$signalTypes,$sourceReferences,$observedAt,$capturedAt,$snapshotId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'capture_account_snapshot',$idempotencyKey,$fingerprint)){
                $snapshot=$this->intelligence->viewSnapshot($organizationId,$snapshotId)
                    ?? throw new InvalidArgumentException('Growth snapshot receipt exists but snapshot was not found.');
                return $snapshot->toArray()+['replayed'=>true];
            }
            $snapshot=new AccountSnapshot(
                $snapshotId,OrganizationId::fromString($organizationId),$accountId,$firmographics,$technologies,$hiring,
                $recentChanges,$signalTypes,$sourceReferences,$observedAt,$capturedAt,
            );
            $this->intelligence->createSnapshot($snapshot,$actorId);
            $this->publish(GrowthEventType::ACCOUNT_SNAPSHOT_CAPTURED,$organizationId,'growth_account',$accountId,[
                'snapshot_id'=>$snapshotId,'source_references'=>$sourceReferences,'signal_types'=>$signalTypes,
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.account.snapshot','growth_account',$accountId,$idempotencyKey,[
                'snapshot_id'=>$snapshotId,
            ]);
            return $snapshot->toArray();
        });
    }

    public function scoreAccount(string $organizationId,int $actorId,string $correlationId,string $accountId,string $profileId,int $revision,string $idempotencyKey): array
    {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        $profileId=$this->bounded(trim($profileId),'profileId',80);
        if($revision<1)throw new InvalidArgumentException('Growth ICP revision must be positive.');
        $fingerprint=$this->fingerprint(['account_id'=>$accountId,'profile_id'=>$profileId,'revision'=>$revision]);
        $matchId='GICM-'.$this->stableId($organizationId.':match:'.$idempotencyKey);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$accountId,$profileId,$revision,$idempotencyKey,$fingerprint,$matchId
        ):array{
            if(!$this->receipts->claim($organizationId,'score_account',$idempotencyKey,$fingerprint)){
                return ($this->intelligence->viewIcpMatch($organizationId,$matchId)
                    ?? throw new InvalidArgumentException('Growth ICP match receipt exists but match was not found.'))
                    + ['replayed'=>true];
            }
            $profile=$this->intelligence->lockIcpProfile($organizationId,$profileId,$revision);
            if($profile->status()!==IcpProfileStatus::Active)throw new InvalidArgumentException('Growth account scoring requires an active ICP profile.');
            $snapshot=$this->intelligence->latestSnapshot($organizationId,$accountId)
                ?? throw new InvalidArgumentException('Growth account requires an AccountSnapshot before ICP scoring.');
            $match=$this->matcher->match($profile,$snapshot,$this->now());
            $this->intelligence->createIcpMatch($matchId,$organizationId,$match,$actorId);
            $this->publish(GrowthEventType::ACCOUNT_ICP_SCORED,$organizationId,'growth_account',$accountId,[
                'profile_id'=>$profileId,'revision'=>$revision,'fit'=>$match->fit->toArray(),
            ],$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'growth.account.icp_score','growth_account',$accountId,$idempotencyKey,[
                'profile_id'=>$profileId,'revision'=>$revision,'fit'=>$match->fit->score,
            ]);
            return $this->intelligence->latestIcpMatch($organizationId,$accountId)
                ?? throw new InvalidArgumentException('Growth ICP match could not be read back.');
        });
    }

    public function accountBrief(string $organizationId,string $accountId): array
    {
        $accountId=$this->bounded(trim($accountId),'accountId',80);
        $account=$this->intelligence->viewAccount($organizationId,$accountId)
            ?? throw new InvalidArgumentException('Growth account was not found.');
        $snapshot=$this->intelligence->latestSnapshot($organizationId,$accountId);
        return [
            'account'=>$account,
            'latest_snapshot'=>$snapshot?->toArray(),
            'icp_match'=>$this->intelligence->latestIcpMatch($organizationId,$accountId),
            'recent_signals'=>$this->growth->listSignalsBySubject($organizationId,'account',$accountId,20),
            'opportunities'=>$this->growth->listCandidatesBySubject($organizationId,'account',$accountId,20),
        ];
    }

    /** @return array<string,scalar|null> */
    private function facts(mixed $value): array
    {
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('firmographics must be an object.');
        foreach($value as $key=>$item){
            if(!is_string($key)||$key===''||(!is_scalar($item)&&$item!==null))throw new InvalidArgumentException('firmographics contains an invalid value.');
        }
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value,string $field): array
    {
        $values=$this->optionalStringList($value,$field);
        if($values===[])throw new InvalidArgumentException($field.' must contain at least one value.');
        return $values;
    }

    /** @return list<string> */
    private function optionalStringList(mixed $value,string $field): array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException($field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException($field.' contains an invalid value.');
            $out[trim($item)]=true;
        }
        return array_keys($out);
    }

    private function date(mixed $value,string $field): DateTimeImmutable
    {
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($field.' is required.');
        try{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
        catch(\Throwable){throw new InvalidArgumentException($field.' is invalid.');}
    }

    /** @param array<string,mixed> $input */
    private function required(array $input,string $key,int $limit): string
    {
        return $this->bounded(trim((string)($input[$key]??'')),$key,$limit);
    }

    private function bounded(string $value,string $field,int $limit): string
    {
        if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function publish(string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,int $actorId,string $correlationId): void
    {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,'USER',(string)$actorId),$this->now(),
        ));
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(string $organizationId,int $actorId,string $correlationId,string $action,string $subjectType,string $subjectId,string $idempotencyKey,array $data=[]): void
    {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.intelligence','USER',(string)$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'idempotency_key_hash'=>hash('sha256',$idempotencyKey),'result'=>$data],
            $correlationId,$this->now(),
        ));
    }

    private function stableId(string $value): string { return strtoupper(substr(hash('sha256',$value),0,20)); }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function now(): DateTimeImmutable { return new DateTimeImmutable('now',new DateTimeZone('UTC')); }
}
