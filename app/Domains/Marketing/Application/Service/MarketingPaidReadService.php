<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DateTimeImmutable;
use DomainException;
use Throwable;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Manual, bounded P0 read sync. Future scheduling must reuse Platform Scheduler. */
final readonly class MarketingPaidReadService
{
    public function __construct(
        private MarketingPaidReadRepository $repository,
        private PaidMediaProviderInterface $provider,
    ) {}

    /** @return array<string,mixed> */
    public function connect(TenantContext $actor,string $externalAccountId,string $credentialRef):array
    {
        $this->guard($actor);
        if(!preg_match('/^env:\/\/[A-Z][A-Z0-9_]{2,127}$/D',$credentialRef))throw new DomainException('Only environment Vault credential references are supported.');
        $verified=$this->provider->inspectAccount($actor->organizationId()->value(),$externalAccountId,$credentialRef);
        return $this->repository->registerAccount($actor->organizationId()->value(),$credentialRef,$verified);
    }

    /** @return list<array<string,mixed>> */
    public function accounts(TenantContext $actor):array
    {
        $this->guard($actor);
        return $this->repository->accounts($actor->organizationId()->value());
    }

    /** @return array<string,int> */
    public function sync(TenantContext $actor,string $accountId,string $from,string $to):array
    {
        $this->guard($actor);
        $org=$actor->organizationId()->value();
        $account=$this->requiredAccount($org,$accountId);
        $dateFrom=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        $dateTo=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$dateFrom||!$dateTo||$dateFrom->format('Y-m-d')!==$from||$dateTo->format('Y-m-d')!==$to
            ||$dateFrom>$dateTo||$dateFrom->diff($dateTo)->days>30){
            throw new DomainException('Meta read sync requires an exact 1-31 day calendar window.');
        }
        $stage='remote';
        try {
            $hierarchy=$this->provider->hierarchy($org,(string)$account['ad_account_id'],(string)$account['credential_ref']);
            $insights=$this->provider->insights($org,(string)$account['ad_account_id'],(string)$account['credential_ref'],$from,$to,'campaign');
            $stage='import';
            // Successful run receipt is atomically committed with all imported facts.
            return $this->repository->importSnapshot($org,$accountId,$from,$to,$hierarchy,$insights);
        }catch(Throwable $failure){
            try {
                $this->repository->recordSyncFailure($org,$accountId,$actor->userId()->value(),
                    $stage==='remote'?'REMOTE_READ_FAILED':'IMPORT_REJECTED');
            }catch(Throwable $receiptFailure){
                error_log('marketing.sync.failure_receipt_unavailable '.$receiptFailure::class);
            }
            throw $failure;
        }
    }

    /** @return list<array<string,mixed>> */
    public function syncHistory(TenantContext $actor,string $accountId,int $limit=20):array
    {
        $this->guard($actor);
        $org=$actor->organizationId()->value();
        $this->requiredAccount($org,$accountId);
        if($limit<1||$limit>50)throw new DomainException('Invalid Meta history page size.');
        return $this->repository->syncHistory($org,$accountId,$limit);
    }

    /** @return array{campaigns:list<array<string,mixed>>,adsets:list<array<string,mixed>>,ads:list<array<string,mixed>>} */
    public function hierarchy(TenantContext $actor,string $accountId):array
    {
        $this->guard($actor);
        $this->requiredAccount($actor->organizationId()->value(),$accountId);
        return $this->repository->hierarchy($actor->organizationId()->value(),$accountId);
    }

    /** @return list<array<string,mixed>> */
    public function campaigns(TenantContext $actor,string $accountId):array
    {
        $this->guard($actor);
        $this->requiredAccount($actor->organizationId()->value(),$accountId);
        return $this->repository->campaigns($actor->organizationId()->value(),$accountId);
    }

    /** @return list<array<string,mixed>> */
    public function insights(TenantContext $actor,string $accountId,string $grain,string $from,string $to):array
    {
        $this->guard($actor);
        $this->requiredAccount($actor->organizationId()->value(),$accountId);
        if(!in_array($grain,['account','campaign','adset','ad'],true))throw new DomainException('Invalid grain.');
        return $this->repository->insights($actor->organizationId()->value(),$accountId,$grain,$from,$to);
    }

    /** @return array<string,mixed> */
    public function spendEvidence(TenantContext $actor,string $accountId,string $sourceSnapshotRef):array
    {
        $this->guard($actor);
        $org=$actor->organizationId()->value();
        $this->requiredAccount($org,$accountId);
        if(!preg_match('/^mis-[a-f0-9]{24}$/D',$sourceSnapshotRef)){
            throw new DomainException('Invalid Marketing insight snapshot identifier.');
        }
        return $this->repository->spendEvidence($org,$accountId,$sourceSnapshotRef);
    }

    private function requiredAccount(string $org,string $id):array
    {
        if(!preg_match('/^mads-[a-f0-9]{24}$/D',$id))throw new DomainException('Invalid account reference.');
        $account=$this->repository->account($org,$id);
        if($account===null||$account['connection_status']!=='READ_ONLY')throw new DomainException('Read-only account unavailable.');
        return $account;
    }

    private function guard(TenantContext $actor):void
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))throw new DomainException('Marketing manager required.');
    }
}
