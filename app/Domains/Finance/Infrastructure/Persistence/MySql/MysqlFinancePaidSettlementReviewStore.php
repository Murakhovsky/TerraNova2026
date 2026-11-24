<?php
declare(strict_types=1);
namespace Domains\Finance\Infrastructure\Persistence\MySql;
use Domains\Finance\Application\Contract\FinancePaidSettlementReviewStore;
use DomainException;
use PDO;

final readonly class MysqlFinancePaidSettlementReviewStore implements FinancePaidSettlementReviewStore
{
    public function __construct(private PDO $connection){}
    public function record(string $org,array $review):array
    {
        $d=$review['document'];$c=$review['candidate'];
        $q=$this->connection->prepare('INSERT IGNORE INTO cos_finance_paid_document_reviews
            (organization_id,review_ref,reservation_id,document_ref,document_version_ref,snapshot_ref,source_revision,document_sha256,source_hash,reviewer_id,candidate_hash,evidence_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$org,$d['review_ref'],$c['reservation_id'],$d['document_ref'],$review['document_version_ref'],$c['source_snapshot_ref'],
            $c['source_revision'],$d['document_sha256'],$c['source_hash'],$d['reviewer_id'],$c['candidate_hash'],json_encode($review,JSON_THROW_ON_ERROR)]);
        $stored=$this->find($org,$c['reservation_id'],$d['document_ref'],$review['document_version_ref'],$c['source_snapshot_ref'],$c['source_revision']);
        if($stored===null||$stored['candidate']['candidate_hash']!==$c['candidate_hash'])throw new DomainException('Review already pinned to different evidence.');
        return $stored;
    }
    public function find(string $org,string $reservationId,string $documentRef,string $versionRef,string $snapshotRef,int $revision):?array
    {
        $q=$this->connection->prepare('SELECT evidence_json,candidate_hash,review_ref,reviewer_id,document_sha256,source_hash
            FROM cos_finance_paid_document_reviews WHERE organization_id=? AND reservation_id=? AND document_ref=? AND document_version_ref=? AND snapshot_ref=? AND source_revision=?'
            .($this->connection->inTransaction()?' FOR UPDATE':''));
        $q->execute([$org,$reservationId,$documentRef,$versionRef,$snapshotRef,$revision]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        $e=json_decode($row['evidence_json'],true,32,JSON_THROW_ON_ERROR);
        if(($e['candidate']['candidate_hash']??null)!==$row['candidate_hash']||($e['document']['review_ref']??null)!==$row['review_ref']
            ||($e['document']['reviewer_id']??null)!==$row['reviewer_id']||($e['document']['document_sha256']??null)!==$row['document_sha256']
            ||($e['document']['source_hash']??null)!==$row['source_hash'])throw new DomainException('Stored review provenance differs.');
        return $e;
    }
}
