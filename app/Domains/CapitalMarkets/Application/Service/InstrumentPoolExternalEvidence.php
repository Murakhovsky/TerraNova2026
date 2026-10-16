<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use PDO;
use Throwable;

/**
 * Proof of independent operator review, not an API confirmation from a provider.
 * Documents MUST be externally verifiable; a hash/URL cannot grant entitlements.
 */
final readonly class InstrumentPoolExternalEvidence
{
    public function __construct(
        private PDO $connection,
        private CapitalMarketsAccessControlInterface $access,
    ){}

    public function assertAuthorized(string $org,int $actor,string $capability):void
    {
        if($org===''||strlen($org)>190||trim($org)!==$org||$actor<1
            ||!$this->access->hasCapability($org,$actor,$capability)){
            throw new DomainException('Independent authorized Capital Markets operator required.');
        }
    }

    public function propose(string $org,int $actor,string $kind,string $subject,
        string $url,string $sha256,string $expires):int
    {
        $this->assertAuthorized($org,$actor,'capital_markets.instrument_pool.propose');
        $this->checkScope($kind,$subject);
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'
            ||!is_string($parts['host']??null)||strlen($url)>800
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])
            ||isset($parts['fragment'])
            ||preg_match('/^[a-z0-9.-]+$/D',$parts['host'])!==1
            ||preg_match('/^[a-f0-9]{64}$/D',$sha256)!==1){
            throw new DomainException('Use permanent HTTPS document URL without tokens and document SHA-256.');
        }
        $utc=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$expires,new \DateTimeZone('UTC'));
        if($utc===false||$utc->format('Y-m-d H:i:s')!==$expires
            ||$utc->getTimestamp()<=time()
            ||$utc->getTimestamp()>time()+30*86400){
            throw new DomainException('External review expiration must be within next 30 days UTC.');
        }
        $stmt=$this->connection->prepare(
            "INSERT INTO tn_capital_market_pool_external_evidence
             (organization_id,kind,subject_id,document_url,document_sha256,
              valid_until,submitted_by,status)
             VALUES (:org,:kind,:subject,:url,:hash,:expires,:actor,'PENDING')"
        );
        $stmt->execute(['org'=>$org,'kind'=>$kind,'subject'=>$subject,'url'=>$url,
            'hash'=>$sha256,'expires'=>$expires,'actor'=>$actor]);
        return (int)$this->connection->lastInsertId();
    }

    public function review(string $org,int $actor,int $id,bool $approve):void
    {
        $this->assertAuthorized($org,$actor,'capital_markets.instrument_pool.approve');
        if($id<1||$this->connection->inTransaction())throw new DomainException('Independent evidence transaction required.');
        $this->connection->beginTransaction();
        try{
            $q=$this->connection->prepare(
                'SELECT submitted_by,status,valid_until
                 FROM tn_capital_market_pool_external_evidence
                 WHERE organization_id=:org AND id=:id FOR UPDATE');
            $q->execute(['org'=>$org,'id'=>$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='PENDING'
                ||(int)$row['submitted_by']===$actor
                ||strtotime($row['valid_until'].' UTC')<=time()){
                throw new DomainException('Independent valid PENDING evidence required.');
            }
            $update=$this->connection->prepare(
                "UPDATE tn_capital_market_pool_external_evidence
                 SET status=:status,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND id=:id AND status='PENDING'");
            $update->execute(['status'=>$approve?'APPROVED':'REJECTED',
                'actor'=>$actor,'org'=>$org,'id'=>$id]);
            if($update->rowCount()!==1)throw new DomainException('Concurrent evidence review.');
            $this->connection->commit();
        }catch(Throwable $error){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    /**
     * Withdrawal is an explicit independently authorised operator decision.
     * Re-reviewing a previously approved document must not revive older proofs.
     */
    public function revoke(string $org,int $actor,int $id,string $reason):void
    {
        $this->assertAuthorized($org,$actor,'capital_markets.instrument_pool.approve');
        if($id<1||mb_strlen(trim($reason))<10||mb_strlen($reason)>500
            ||$this->connection->inTransaction()){
            throw new DomainException('Revocation needs an independent actor and documented reason.');
        }
        $this->connection->beginTransaction();
        try{
            $q=$this->connection->prepare(
                'SELECT submitted_by,status FROM tn_capital_market_pool_external_evidence
                 WHERE organization_id=:org AND id=:id FOR UPDATE');
            $q->execute(['org'=>$org,'id'=>$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='APPROVED'
                ||(int)$row['submitted_by']===$actor){
                throw new DomainException('Current independently approved attestation required.');
            }
            $change=$this->connection->prepare(
                "UPDATE tn_capital_market_pool_external_evidence
                 SET status='REJECTED',reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(6),
                     review_reason=:reason
                 WHERE organization_id=:org AND id=:id AND status='APPROVED'");
            $change->execute(['actor'=>$actor,'reason'=>trim($reason),
                'org'=>$org,'id'=>$id]);
            if($change->rowCount()!==1)throw new DomainException('Concurrent attestation revocation.');
            $this->connection->commit();
        }catch(Throwable $error){
            if($this->connection->inTransaction())$this->connection->rollBack();
            throw $error;
        }
    }

    /** @return array<string,bool> */
    public function approved(string $org):array
    {
        return array_fill_keys(array_keys($this->approvedDigests($org)),true);
    }

    /** @return array<string,string> Latest independently reviewed, unexpired SHA-256 per exact scope. */
    public function approvedDigests(string $org):array
    {
        if($org===''||strlen($org)>190||trim($org)!==$org)throw new DomainException('Invalid tenant.');
        $q=$this->connection->prepare(
            "SELECT kind,subject_id,status,valid_until,submitted_by,reviewed_by,document_sha256
             FROM tn_capital_market_pool_external_evidence
             WHERE organization_id=:org ORDER BY kind,subject_id,id DESC");
        $q->execute(['org'=>$org]);
        $out=[];$processed=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $key=$row['kind'].'|'.$row['subject_id'];
            if(isset($processed[$key]))continue;
            $processed[$key]=true;
            if($row['status']!=='APPROVED'||$row['reviewed_by']===null
                ||(int)$row['reviewed_by']===(int)$row['submitted_by']
                ||strtotime($row['valid_until'].' UTC')<=time()
                ||preg_match('/^[a-f0-9]{64}$/D',(string)$row['document_sha256'])!==1)continue;
            $out[$key]=(string)$row['document_sha256'];
        }
        return $out;
    }

    private function checkScope(string $kind,string $subject):void
    {
        if(!in_array($kind,['SOURCE_ENTITLEMENT','VENUE_ENTITLEMENT','MASSIVE_NBBO_ENTITLEMENT','ISSUER_IDENTITY'],true)
            ||$subject===''||strlen($subject)>190||trim($subject)!==$subject){
            throw new DomainException('Invalid production evidence subject.');
        }
    }
}
