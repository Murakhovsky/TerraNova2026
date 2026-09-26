<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceBoundary;
use Domains\Growth\Application\Contract\GrowthIntelligenceRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Domains\Growth\Application\Contract\GrowthMarketDiscoveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\DTO\GrowthMarketDiscoveredAccount;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\GrowthMarketUniverse;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\IcpProfileStatus;
use Domains\Growth\Domain\OpportunityType;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final readonly class GrowthMarketDiscoveryService implements GrowthMarketDiscoveryBoundary
{
    public function __construct(
        private GrowthMarketDiscoveryRepositoryInterface $repository,
        private GrowthMarketSourceRegistry $sources,
        private GrowthIntelligenceBoundary $intelligence,
        private GrowthIntelligenceRepositoryInterface $intelligenceRepository,
        private GrowthApplicationBoundary $growth,
        private GrowthRepositoryInterface $growthRepository,
        private GrowthMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
        private AuditRepositoryInterface $audit,
    ) {}

    public function createUniverse(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $name=$this->input($input,'name',160);
        $sourceType=strtolower(trim((string)($input['source_type']??GrowthMarketUniverse::SOURCE_CREDENTIALED_JSON)));
        $this->sources->get($sourceType);
        $url=$this->input($input,'url',1000);
        $authMode=$this->input($input,'auth_mode',40);
        $credentialReference=$this->input($input,'credential_reference',500);
        $apiKeyHeader=$this->nullableString($input['api_key_header']??null,80,'api_key_header');
        $profileId=$this->input($input,'profile_id',80);
        $profileRevision=$this->positiveInt($input['profile_revision']??null,'profile_revision');
        $profile=$this->intelligenceRepository->viewIcpProfile($organizationId,$profileId,$profileRevision)
            ??throw new InvalidArgumentException('Growth Market Universe ICP profile was not found.');
        if((string)($profile['status']??'')!==IcpProfileStatus::Active->value){
            throw new InvalidArgumentException('Growth Market Universe requires an active ICP revision.');
        }
        $minIcpFit=$this->rangeInt($input['min_icp_fit']??70,'min_icp_fit',0,100);
        $opportunityType=OpportunityType::tryFrom($this->input($input,'opportunity_type',80))
            ??throw new InvalidArgumentException('Growth Market Universe opportunity_type is invalid.');
        $growthMode=GrowthMode::tryFrom($this->input($input,'growth_mode',40))
            ??throw new InvalidArgumentException('Growth Market Universe growth_mode is invalid.');
        $targetDomain=strtolower($this->input($input,'target_domain',80));
        $enabled=$input['enabled']??true;
        if(!is_bool($enabled))throw new InvalidArgumentException('enabled must be boolean.');

        $universeId='GMU-'.$this->stableId($organizationId.':market_universe:'.$idempotencyKey);
        $fingerprint=$this->fingerprint([
            'name'=>$name,'source_type'=>$sourceType,'url'=>$url,'auth_mode'=>$authMode,
            'credential_reference_hash'=>hash('sha256',$credentialReference),'api_key_header'=>$apiKeyHeader,
            'profile_id'=>$profileId,'profile_revision'=>$profileRevision,'min_icp_fit'=>$minIcpFit,
            'opportunity_type'=>$opportunityType->value,'growth_mode'=>$growthMode->value,'target_domain'=>$targetDomain,'enabled'=>$enabled,
        ]);

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$idempotencyKey,$universeId,$fingerprint,$name,$sourceType,$url,$authMode,
            $credentialReference,$apiKeyHeader,$profileId,$profileRevision,$minIcpFit,$opportunityType,$growthMode,$targetDomain,$enabled
        ):array{
            if(!$this->receipts->claim($organizationId,'create_market_universe',$idempotencyKey,$fingerprint)){
                $row=$this->repository->viewUniverse($organizationId,$universeId)
                    ??throw new InvalidArgumentException('Growth market universe receipt exists but Universe was not found.');
                return $this->publicUniverse($row)+['replayed'=>true];
            }
            $universe=new GrowthMarketUniverse(
                $universeId,OrganizationId::fromString($organizationId),$name,$sourceType,$url,$authMode,$credentialReference,
                $apiKeyHeader,$profileId,$profileRevision,$minIcpFit,$opportunityType,$growthMode,$targetDomain,$enabled,
            );
            $this->repository->createUniverse($universe,$actorId);
            $this->publish(GrowthEventType::MARKET_UNIVERSE_CREATED,$organizationId,'growth_market_universe',$universeId,[
                'name'=>$name,'source_type'=>$sourceType,'profile_id'=>$profileId,'profile_revision'=>$profileRevision,
                'min_icp_fit'=>$minIcpFit,'opportunity_type'=>$opportunityType->value,'growth_mode'=>$growthMode->value,
                'target_domain'=>$targetDomain,'enabled'=>$enabled,
            ],'USER',(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,'USER',(string)$actorId,$correlationId,'growth.market.universe_created','growth_market_universe',$universeId,[
                'source_url_hash'=>hash('sha256',$url),'credential_reference_hash'=>hash('sha256',$credentialReference),
                'profile_id'=>$profileId,'profile_revision'=>$profileRevision,'min_icp_fit'=>$minIcpFit,
            ]);
            return $this->publicUniverse(
                $this->repository->viewUniverse($organizationId,$universeId)
                    ??throw new InvalidArgumentException('Created Growth Market Universe could not be read back.')
            );
        });
    }

    public function setEnabled(string $organizationId,int $actorId,string $correlationId,string $universeId,bool $enabled,string $idempotencyKey):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $universeId=$this->bounded($universeId,'universeId',80);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        $fingerprint=$this->fingerprint(['universe_id'=>$universeId,'enabled'=>$enabled]);
        $operation=$enabled?'enable_market_universe':'disable_market_universe';

        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$universeId,$enabled,$idempotencyKey,$fingerprint,$operation
        ):array{
            if(!$this->receipts->claim($organizationId,$operation,$idempotencyKey,$fingerprint)){
                return $this->publicUniverse(
                    $this->repository->viewUniverse($organizationId,$universeId)
                        ??throw new InvalidArgumentException('Growth Market Universe toggle receipt exists but Universe was not found.')
                )+['replayed'=>true];
            }
            $universe=$this->repository->lockUniverse($organizationId,$universeId);
            if($enabled)$universe->enable();else$universe->disable();
            $this->repository->updateUniverse($universe,$actorId);
            $this->publish(
                $enabled?GrowthEventType::MARKET_UNIVERSE_ENABLED:GrowthEventType::MARKET_UNIVERSE_DISABLED,
                $organizationId,'growth_market_universe',$universeId,['enabled'=>$enabled],
                'USER',(string)$actorId,$correlationId,
            );
            $this->appendAudit($organizationId,'USER',(string)$actorId,$correlationId,'growth.market.universe_toggled','growth_market_universe',$universeId,['enabled'=>$enabled]);
            return $this->publicUniverse(
                $this->repository->viewUniverse($organizationId,$universeId)
                    ??throw new InvalidArgumentException('Updated Growth Market Universe could not be read back.')
            );
        });
    }

    public function runUniverse(string $organizationId,int $actorId,string $correlationId,string $universeId,string $idempotencyKey,int $limit=100):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $universeId=$this->bounded($universeId,'universeId',80);
        $idempotencyKey=$this->bounded($idempotencyKey,'idempotencyKey',191);
        if($limit<1||$limit>200)throw new InvalidArgumentException('Growth market run limit must be between 1 and 200.');
        $runId='GMRN-'.$this->stableId($organizationId.':market_run:'.$idempotencyKey);
        $fingerprint=$this->fingerprint(['universe_id'=>$universeId,'limit'=>$limit]);

        $setup=$this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$universeId,$idempotencyKey,$limit,$runId,$fingerprint
        ):array{
            if(!$this->receipts->claim($organizationId,'run_market_discovery',$idempotencyKey,$fingerprint)){
                return ['replay'=>$this->repository->viewRun($organizationId,$runId)
                    ??throw new InvalidArgumentException('Growth market run receipt exists but run was not found.')];
            }
            $universe=$this->repository->lockUniverse($organizationId,$universeId);
            if(!$universe->enabled())throw new InvalidArgumentException('Growth Market Universe is disabled.');
            $this->repository->createRun($organizationId,$runId,$universeId,$limit,$actorId);
            $this->publish(GrowthEventType::MARKET_DISCOVERY_RUN_STARTED,$organizationId,'growth_market_universe',$universeId,[
                'run_id'=>$runId,'limit'=>$limit,'source_type'=>$universe->sourceType,
            ],'SYSTEM',(string)$actorId,$correlationId);
            $row=$this->repository->viewUniverse($organizationId,$universeId)
                ??throw new InvalidArgumentException('Growth Market Universe disappeared during run setup.');
            return ['replay'=>null,'universe'=>$universe,'cursor'=>$row['cursor']??null];
        });
        if(is_array($setup['replay']??null))return $setup['replay']+['replayed'=>true];

        $universe=$setup['universe'];
        if(!$universe instanceof GrowthMarketUniverse)throw new InvalidArgumentException('Growth market run lost Universe configuration.');
        $cursor=is_string($setup['cursor']??null)?(string)$setup['cursor']:null;

        try{
            $batch=$this->sources->get($universe->sourceType)->discover($universe,$cursor,$limit);
        }catch(Throwable $error){
            $summary=$this->errorSummary($error);
            return $this->finishRun(
                $organizationId,$actorId,$correlationId,$universeId,$runId,'failed',
                0,0,0,0,0,$cursor,$summary
            );
        }

        $accounts=0;$existing=0;$monitored=0;$opportunities=0;$failed=0;$errors=[];
        foreach($batch->items as $item){
            try{
                $result=$this->processItem($organizationId,$actorId,$correlationId,$universe,$item);
                $accounts++;
                if(!empty($result['existing']))$existing++;
                if(($result['status']??'')==='monitoring')$monitored++;
                if(($result['status']??'')==='opportunity')$opportunities++;
            }catch(Throwable $error){
                $failed++;
                if(count($errors)<5)$errors[]=$this->errorSummary($error);
            }
        }
        $status=$failed>0?'partial':'completed';
        $summary=$errors===[]?null:mb_substr(implode(' | ',$errors),0,2000);
        return $this->finishRun(
            $organizationId,$actorId,$correlationId,$universeId,$runId,$status,
            count($batch->items),$accounts,$existing,$monitored,$opportunities,$batch->nextCursor,$summary
        );
    }

    public function universes(string $organizationId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        return array_map(fn(array $row):array=>$this->publicUniverse($row),$this->repository->listUniverses($organizationId,200));
    }

    public function universeBrief(string $organizationId,string $universeId):array
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $universeId=$this->bounded($universeId,'universeId',80);
        $universe=$this->repository->viewUniverse($organizationId,$universeId)
            ??throw new InvalidArgumentException('Growth Market Universe was not found.');
        return [
            'universe'=>$this->publicUniverse($universe),
            'memberships'=>$this->repository->membershipsForUniverse($organizationId,$universeId,200),
            'runs'=>$this->repository->latestRuns($organizationId,$universeId,20),
            'source_types'=>$this->sources->types(),
        ];
    }

    public function considerSignal(string $organizationId,string $accountId,string $signalId,string $correlationId):void
    {
        $organizationId=$this->bounded($organizationId,'organizationId',64);
        $accountId=$this->bounded($accountId,'accountId',80);
        $signalId=$this->bounded($signalId,'signalId',80);
        $correlationId=$this->bounded($correlationId,'correlationId',191);
        if($this->growthRepository->viewSignal($organizationId,$signalId)===null)return;

        foreach($this->repository->membershipsForAccount($organizationId,$accountId) as $membership){
            if(empty($membership['enabled'])||!empty($membership['candidate_id']))continue;
            if((int)($membership['fit_score']??0)<(int)($membership['min_icp_fit']??101))continue;
            $this->materializeOpportunity($organizationId,0,$correlationId,$membership,$signalId);
        }
    }

    /** @return array<string,mixed> */
    private function processItem(
        string $organizationId,int $actorId,string $correlationId,GrowthMarketUniverse $universe,GrowthMarketDiscoveredAccount $item
    ):array {
        $externalHash=hash('sha256',$item->externalKey);
        $observationHash=substr(hash('sha256',json_encode([
            'external_key'=>$externalHash,'observed_at'=>$item->observedAt->format(DATE_ATOM),
            'sources'=>$item->sourceReferences,'domain'=>strtolower($item->canonicalDomain),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),0,32);
        $baseKey='market:'.$universe->id.':'.$externalHash.':'.$observationHash;

        $account=$this->intelligence->discoverAccount($organizationId,$actorId,$correlationId,$baseKey.':account',[
            'name'=>$item->name,'canonical_domain'=>strtolower($item->canonicalDomain),
        ]);
        $accountId=(string)($account['account_id']??'');
        if($accountId==='')throw new InvalidArgumentException('Growth market discovery did not produce an Account id.');

        $this->intelligence->captureAccountSnapshot($organizationId,$actorId,$correlationId,$accountId,$baseKey.':snapshot',[
            'firmographics'=>$item->firmographics,'technologies'=>$item->technologies,'hiring'=>$item->hiring,
            'recent_changes'=>$item->recentChanges,'signal_types'=>$item->signalTypes,
            'source_references'=>$item->sourceReferences,'observed_at'=>$item->observedAt->format(DATE_ATOM),
        ]);
        $match=$this->intelligence->scoreAccount(
            $organizationId,$actorId,$correlationId,$accountId,$universe->profileId,$universe->profileRevision,$baseKey.':score'
        );
        $fit=(int)($match['fit_score']??($match['fit']['score']??0));
        $status=$fit>=$universe->minIcpFit?'monitoring':'below_threshold';
        $this->repository->upsertMembership(
            $organizationId,$universe->id,$accountId,$externalHash,$fit,$status,$item->sourceReferences[0]
        );

        $membership=null;
        foreach($this->repository->membershipsForAccount($organizationId,$accountId) as $row){
            if((string)($row['universe_id']??'')===$universe->id){$membership=$row;break;}
        }
        if($membership===null)throw new InvalidArgumentException('Growth market membership could not be read back.');

        if($fit>=$universe->minIcpFit&&!empty($membership['candidate_id'])){
            $status='opportunity';
        }elseif($fit>=$universe->minIcpFit){
            $signals=$this->growthRepository->listSignalsBySubject($organizationId,'account',$accountId,20);
            if($signals!==[]){
                $signalId=trim((string)($signals[0]['signal_id']??''));
                if($signalId!==''){
                    $candidate=$this->materializeOpportunity($organizationId,$actorId,$correlationId,$membership,$signalId);
                    if($candidate!==null)$status='opportunity';
                }
            }
        }

        $this->publish(GrowthEventType::MARKET_ACCOUNT_SOURCED,$organizationId,'growth_account',$accountId,[
            'universe_id'=>$universe->id,'fit_score'=>$fit,'status'=>$status,'source_reference'=>$item->sourceReferences[0],
        ],'SYSTEM',(string)$actorId,$correlationId);

        return ['account_id'=>$accountId,'fit_score'=>$fit,'status'=>$status,'existing'=>!empty($account['existing'])];
    }

    /** @param array<string,mixed> $membership @return array<string,mixed>|null */
    private function materializeOpportunity(
        string $organizationId,int $actorId,string $correlationId,array $membership,string $signalId
    ):?array {
        if(!empty($membership['candidate_id']))return $this->growthRepository->viewCandidate($organizationId,(string)$membership['candidate_id']);
        $universeId=(string)$membership['universe_id'];
        $accountId=(string)$membership['account_id'];
        $triggerSignal=$this->repository->claimOpportunityTrigger($organizationId,$universeId,$accountId,$signalId);

        foreach($this->growthRepository->listCandidatesBySubject($organizationId,'account',$accountId,50) as $candidate){
            if((string)($candidate['target_domain']??'')!==(string)$membership['target_domain'])continue;
            if((string)($candidate['growth_mode']??'')!==(string)$membership['growth_mode'])continue;
            $candidateId=trim((string)($candidate['candidate_id']??''));
            if($candidateId==='')continue;
            $this->repository->setMembershipCandidate($organizationId,$universeId,$accountId,$candidateId);
            return $candidate;
        }

        $candidate=$this->growth->detectCandidate(
            $organizationId,$actorId,$correlationId,'market-opportunity:'.$universeId.':'.$accountId,[
                'opportunity_type'=>(string)$membership['opportunity_type'],
                'growth_mode'=>(string)$membership['growth_mode'],
                'subject_type'=>'account','subject_id'=>$accountId,
                'target_domain'=>(string)$membership['target_domain'],'signal_ids'=>[$triggerSignal],
            ],
        );
        $candidateId=trim((string)($candidate['candidate_id']??''));
        if($candidateId==='')throw new InvalidArgumentException('Growth market opportunity did not produce a Candidate id.');
        $this->repository->setMembershipCandidate($organizationId,$universeId,$accountId,$candidateId);
        $this->publish(GrowthEventType::MARKET_OPPORTUNITY_DETECTED,$organizationId,'growth_candidate',$candidateId,[
            'universe_id'=>$universeId,'account_id'=>$accountId,'trigger_signal_id'=>$triggerSignal,
            'fit_score'=>(int)$membership['fit_score'],
        ],'SYSTEM',(string)$actorId,$correlationId);
        return $candidate;
    }

    private function finishRun(
        string $organizationId,int $actorId,string $correlationId,string $universeId,string $runId,string $status,
        int $collected,int $accounts,int $existing,int $monitored,int $opportunities,?string $nextCursor,?string $summary
    ):array {
        return $this->transactions->transactional(function()use(
            $organizationId,$actorId,$correlationId,$universeId,$runId,$status,$collected,$accounts,$existing,
            $monitored,$opportunities,$nextCursor,$summary
        ):array{
            $this->repository->completeRun(
                $organizationId,$runId,$status,$collected,$accounts,$existing,$monitored,$opportunities,$nextCursor,$summary
            );
            if($status!=='failed')$this->repository->updateRuntime($organizationId,$universeId,$nextCursor);
            $this->publish(
                $status==='failed'?GrowthEventType::MARKET_DISCOVERY_RUN_FAILED:GrowthEventType::MARKET_DISCOVERY_RUN_COMPLETED,
                $organizationId,'growth_market_universe',$universeId,[
                    'run_id'=>$runId,'status'=>$status,'collected_count'=>$collected,'account_count'=>$accounts,
                    'existing_count'=>$existing,'monitored_count'=>$monitored,'opportunity_count'=>$opportunities,
                    'next_cursor'=>$nextCursor,'error_summary'=>$summary,
                ],'SYSTEM',(string)$actorId,$correlationId,
            );
            $this->appendAudit($organizationId,'SYSTEM',(string)$actorId,$correlationId,'growth.market.discovery_run','growth_market_universe',$universeId,[
                'run_id'=>$runId,'status'=>$status,'collected_count'=>$collected,'account_count'=>$accounts,
                'monitored_count'=>$monitored,'opportunity_count'=>$opportunities,'error_summary'=>$summary,
            ]);
            return $this->repository->viewRun($organizationId,$runId)
                ??throw new InvalidArgumentException('Completed Growth market run could not be read back.');
        });
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicUniverse(array $row):array
    {
        $reference=(string)($row['credential_reference']??'');
        unset($row['credential_reference']);
        $row['credential_configured']=$reference!=='';
        $row['credential_reference_hash']=$reference===''?null:substr(hash('sha256',$reference),0,16);
        $row['url_hash']=isset($row['source_url'])?substr(hash('sha256',(string)$row['source_url']),0,16):null;
        return $row;
    }

    private function input(array $input,string $key,int $limit):string{return $this->bounded((string)($input[$key]??''),$key,$limit);}

    private function bounded(string $value,string $field,int $limit):string
    {
        $value=trim($value);if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');return $value;
    }

    private function nullableString(mixed $value,int $limit,string $field):?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value))throw new InvalidArgumentException($field.' must be a string or null.');
        $value=trim($value);if($value===''||mb_strlen($value)>$limit)throw new InvalidArgumentException($field.' is invalid.');return $value;
    }

    private function positiveInt(mixed $value,string $field):int{return $this->rangeInt($value,$field,1,PHP_INT_MAX);}

    private function rangeInt(mixed $value,string $field,int $min,int $max):int
    {
        if(is_string($value)&&ctype_digit(trim($value)))$value=(int)trim($value);
        if(!is_int($value)||$value<$min||$value>$max)throw new InvalidArgumentException($field.' is invalid.');
        return $value;
    }

    private function stableId(string $value):string{return strtoupper(substr(hash('sha256',$value),0,20));}

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        ksort($value,SORT_STRING);
        return hash('sha256',(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function errorSummary(Throwable $error):string
    {
        $message=trim($error->getMessage());if($message==='')$message=get_class($error);
        return mb_substr($message,0,1000);
    }

    /** @param array<string,mixed> $payload */
    private function publish(
        string $type,string $organizationId,string $aggregateType,string $aggregateId,array $payload,
        string $actorType,string $actorId,string $correlationId
    ):void {
        $this->events->publish(new DomainEvent(
            bin2hex(random_bytes(16)),$organizationId,$type,$aggregateType,$aggregateId,$payload,
            new EventMetadata($correlationId,null,$actorType,$actorId),new DateTimeImmutable('now',new DateTimeZone('UTC')),
        ));
    }

    /** @param array<string,mixed> $result */
    private function appendAudit(
        string $organizationId,string $actorType,string $actorId,string $correlationId,string $action,
        string $subjectType,string $subjectId,array $result
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),$organizationId,'growth.market_discovery',$actorType,$actorId,
            $subjectType,$subjectId,null,['action'=>$action,'result'=>$result],$correlationId,
            new DateTimeImmutable('now',new DateTimeZone('UTC')),
        ));
    }
}
