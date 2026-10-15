<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use PDO;

/**
 * Two-person operator review of external source/issuer documents.
 * This attests human review only: it NEVER queries an issuer or grants a
 * license, entitlement, verified economic relationship or trading rights.
 */
final readonly class InstrumentPoolExternalAttestations
{
    public function __construct(
        private PDO $pdo,
        private CapitalMarketsAccessControlInterface $access,
    ){}

    public function propose(string $org,int $actor,string $kind,string $subject,
        string $url,string $sha256,string $validUntil):int
    {
        $this->authorize($org,$actor,'capital_markets.instrument_pool.propose');
        $this->scope($kind,$subject);
        $parsed=parse_url($url);
        if(!is_array($parsed)||($parsed['scheme']??null)!=='https'
            ||!is_string($parsed['host']??null)
            ||strlen($url)>800||isset($parsed['user'])||isset($parsed['pass'])
            ||isset($parsed['fragment'])||isset($parsed['query'])
            ||preg_match('/^[a-z0-9.-]+$/D',$parsed['host'])!==1
            ||preg_match('/^[a-f0-9]{64}$/D',$sha256)!==1){
            throw new DomainException('Evidence requires stable HTTPS document URL and SHA-256; no secrets.');
        }
        $time=strtotime($validUntil.' UTC');
        if($time===false||$time<=time()||$time>time()+30*86400){
            throw new DomainException('External document review expires within 30 days.');
        }
        $stmt=$this->pdo->prepare(
            "INSERT INTO tn_capital_market_production_attestations
             (organization_id,evidence_kind,subject_id,evidence_url,
              evidence_sha256,valid_until,submitted_by,status)
             VALUES (:org,:kind,:subject,:url,:hash,:expiry,:actor,'PENDING')"
        );
        $stmt->execute([
            'org'=>$org,'kind'=>$kind,'subject'=>$subject,'url'=>$url,
            'hash'=>$sha256,'expiry'=>gmdate('Y-m-d H:i:s',$time),'actor'=>$actor,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function decide(string $org,int $actor,int $id,bool $approve):void
    {
        $this->authorize($org,$actor,'capital_markets.instrument_pool.approve');
        if($id<1)throw new DomainException('Invalid attestation ID.');
        if($this->pdo->inTransaction())throw new DomainException('Attestation approval needs own transaction.');
        $this->pdo->beginTransaction();
        try{
            $read=$this->pdo->prepare(
                'SELECT submitted_by,status,valid_until
                 FROM tn_capital_market_production_attestations
                 WHERE organization_id=:org AND id=:id FOR UPDATE'
            );
            $read->execute(['org'=>$org,'id'=>$id]);
            $row=$read->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['status']!=='PENDING'
                ||(int)$row['submitted_by']===$actor
                ||strtotime($row['valid_until'].' UTC')<=time()){
                throw new DomainException('Pending independent current evidence required.');
            }
            $q=$this->pdo->prepare(
                'UPDATE tn_capital_market_production_attestations
                 SET status=:status,reviewed_by=:actor,decided_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND id=:id AND status=\'PENDING\''
            );
            $q->execute(['status'=>$approve?'APPROVED':'REJECTED',
                'actor'=>$actor,'org'=>$org,'id'=>$id]);
            if($q->rowCount()!==1)throw new DomainException('Concurrent evidence review.');
            $this->pdo->commit();
        }catch(\Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,bool> */
    public function approved(string $org):array
    {
        if($org===''||trim($org)!==$org||strlen($org)>190)throw new DomainException('Invalid tenant.');
        $q=$this->pdo->prepare(
            "SELECT evidence_kind,subject_id FROM tn_capital_market_production_attestations
             WHERE organization_id=:org AND status='APPROVED'
               AND valid_until>UTC_TIMESTAMP(6)
               AND reviewed_by IS NOT NULL AND reviewed_by<>submitted_by"
        );
        $q->execute(['org'=>$org]);
        $seen=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $seen[$row['evidence_kind'].'|'.$row['subject_id']]=true;
        }
        return $seen;
    }

    private function scope(string $kind,string $subject):void
    {
        if(!in_array($kind,['VENUE_ENTITLEMENT','MASSIVE_NBBO_ENTITLEMENT','ISSUER_IDENTITY'],true)
            ||$subject===''||trim($subject)!==$subject||strlen($subject)>190){
            throw new DomainException('Invalid evidence scope.');
        }
    }
    private function authorize(string $org,int $actor,string $cap):void
    {
        if($org===''||trim($org)!==$org||strlen($org)>190
            ||$actor<1||!$this->access->hasCapability($org,$actor,$cap)){
            throw new DomainException('Production evidence operator capability required.');
        }
    }
}
