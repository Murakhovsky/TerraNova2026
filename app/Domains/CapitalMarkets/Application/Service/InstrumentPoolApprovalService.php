<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Command\RegisterVenueInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsFoundationBoundary;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlInstrumentPoolSnapshotRepository;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use PDO;

/**
 * Human-governed import into the existing Foundation: no parallel registry,
 * no trading subscriptions and no autonomous symbol-to-asset inference.
 */
final readonly class InstrumentPoolApprovalService
{
    public function __construct(
        private PDO $pdo,
        private MysqlInstrumentPoolSnapshotRepository $snapshots,
        private InstrumentPoolLifecycleReviewer $reviewer,
        private CapitalMarketsFoundationBoundary $foundation,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsAuditTrail $audit,
        private TransactionManagerInterface $transactions,
    ){}

    /** @return list<array<string,mixed>> */
    public function queue(string $org,int $actor,int $limit=100):array
    {
        $this->allow($org,$actor,'capital_markets.instrument_pool.view');
        if($limit<1||$limit>200)throw new DomainException('Invalid review queue limit.');
        $q=$this->pdo->prepare(
            'SELECT id,snapshot_id,provider,venue_id,venue_symbol,action,
                    proposed_payload,evidence_digest,reason,proposed_by,reviewed_by,
                    review_reason,status,result_json,created_at,decided_at
             FROM tn_capital_market_instrument_pool_changes
             WHERE organization_id=:org ORDER BY id DESC LIMIT '.$limit
        );
        $q->execute(['org'=>$org]);
        return array_map(static function(array $row):array{
            $row['id']=(int)$row['id'];$row['snapshot_id']=(int)$row['snapshot_id'];
            $row['proposed_by']=(int)$row['proposed_by'];
            $row['reviewed_by']=$row['reviewed_by']===null?null:(int)$row['reviewed_by'];
            $row['proposed_payload']=json_decode((string)$row['proposed_payload'],true,64,JSON_THROW_ON_ERROR);
            $row['result_json']=$row['result_json']===null?null:json_decode((string)$row['result_json'],true,64,JSON_THROW_ON_ERROR);
            return $row;
        },$q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * CREATE: explicit new instrument id + economics, mapped SUSPENDED.
     * LINK: explicit existing active instrument id, mapped SUSPENDED.
     * STATUS: ACTIVE only on observed existing mapping; SUSPENDED requires
     * three consecutive healthy absence observations. Never auto-DELIST.
     *
     * @param array<string,mixed> $input
     */
    public function propose(string $org,int $actor,array $input,string $correlationId):int
    {
        $this->allow($org,$actor,'capital_markets.instrument_pool.propose');
        $snapshotId=$this->positive($input['snapshot_id']??null);
        $action=$this->literal($input['action']??null,['CREATE','LINK','STATUS']);
        $symbol=$this->text($input['venue_symbol']??null,120);
        $reason=$this->reason($input['reason']??null);
        return $this->transactions->transactional(function()use($org,$actor,$snapshotId,$action,$symbol,$reason,$input,$correlationId):int{
            $snapshot=$this->snapshot($org,$snapshotId);
            $row=$this->observedRow($snapshot,$symbol);
            $this->assertProposable($org,$snapshot,$row,$action,$input);
            $payload=match($action){
                'CREATE'=>[
                    'instrument_id'=>$this->text($input['instrument_id']??null,190),
                    'symbol'=>$this->text($input['symbol']??null,64),
                    'canonical_symbol'=>$this->text($input['canonical_symbol']??null,64),
                    'name'=>$this->text($input['name']??null,190),
                    'family'=>InstrumentFamily::from($this->text($input['family']??null,48))->value,
                ],
                'LINK'=>['instrument_id'=>$this->text($input['instrument_id']??null,190)],
                'STATUS'=>[
                    'instrument_id'=>$row['instrument_id'],
                    'target_status'=>$this->literal($input['target_status']??null,['ACTIVE','SUSPENDED']),
                ],
            };
            $digest=$this->digest($row);
            $statement=$this->pdo->prepare(
                'INSERT INTO tn_capital_market_instrument_pool_changes
                (organization_id,snapshot_id,provider,venue_id,venue_symbol,action,
                 proposed_payload,evidence_digest,reason,proposed_by,status)
                VALUES (:org,:snapshot,:provider,:venue,:symbol,:action,
                 :payload,:digest,:reason,:actor,\'PENDING\')'
            );
            $statement->execute([
                'org'=>$org,'snapshot'=>$snapshotId,'provider'=>$snapshot['provider'],
                'venue'=>$snapshot['venue_id'],'symbol'=>$symbol,'action'=>$action,
                'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),
                'digest'=>$digest,'reason'=>$reason,'actor'=>$actor,
            ]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit->record($org,$actor,CapitalMarketsAuditAction::InstrumentPoolProposed,
                CapitalMarketsAuditResourceType::InstrumentPoolChange,(string)$id,[],
                ['action'=>$action,'symbol'=>$symbol,'snapshot_id'=>$snapshotId,'digest'=>$digest],
                $correlationId);
            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function decide(string $org,int $actor,int $id,bool $approve,string $reason,string $correlationId):array
    {
        $this->allow($org,$actor,'capital_markets.instrument_pool.approve');
        if($id<1)throw new DomainException('Invalid change ID.');
        $reason=$this->reason($reason);
        return $this->transactions->transactional(function()use($org,$actor,$id,$approve,$reason,$correlationId):array{
            $get=$this->pdo->prepare(
                'SELECT * FROM tn_capital_market_instrument_pool_changes
                 WHERE organization_id=:org AND id=:id FOR UPDATE'
            );
            $get->execute(['org'=>$org,'id'=>$id]);
            $change=$get->fetch(PDO::FETCH_ASSOC);
            if(!is_array($change))throw new DomainException('Instrument Pool change not found.');
            if((int)$change['proposed_by']===$actor)throw new DomainException('No self-approval.');
            if($change['status']!=='PENDING')throw new DomainException('Change already decided.');
            $payload=json_decode((string)$change['proposed_payload'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new DomainException('Corrupt proposed payload.');
            $result=['decision'=>$approve?'APPLIED':'REJECTED','action'=>$change['action'],
                'venue_symbol'=>$change['venue_symbol'],'instrument_id'=>$payload['instrument_id']??null];
            if($approve){
                // The most recent independent source evidence is rechecked at the
                // decision boundary, never trusting the stale proposed JSON.
                $latest=$this->latestHealthy($org,(string)$change['provider'],(string)$change['venue_id']);
                $row=$this->observedRow($latest,(string)$change['venue_symbol']);
                $original=$this->snapshot($org,(int)$change['snapshot_id'],false);
                $oldRow=$this->observedRow($original,(string)$change['venue_symbol']);
                if($this->digest($row)!==(string)$change['evidence_digest']
                    ||$this->digest($oldRow)!==(string)$change['evidence_digest']){
                    throw new DomainException('Provider evidence or canonical mapping changed. Re-propose.');
                }
                $this->assertProposable($org,$latest,$row,(string)$change['action'],$payload);
                $this->apply($org,$actor,$change,$row,$payload,$correlationId);
                $result['evidence_sha256']=$latest['evidence_sha256'];
            }
            $new=$approve?'APPLIED':'REJECTED';
            $write=$this->pdo->prepare(
                'UPDATE tn_capital_market_instrument_pool_changes
                 SET status=:status,reviewed_by=:actor,review_reason=:reason,
                     result_json=:result,decided_at=UTC_TIMESTAMP(6)
                 WHERE id=:id AND organization_id=:org AND status=\'PENDING\''
            );
            $write->execute([
                'status'=>$new,'actor'=>$actor,'reason'=>$reason,
                'result'=>json_encode($result,JSON_THROW_ON_ERROR),
                'id'=>$id,'org'=>$org,
            ]);
            if($write->rowCount()!==1)throw new DomainException('Concurrent approval conflict.');
            $this->audit->record($org,$actor,
                $approve?CapitalMarketsAuditAction::InstrumentPoolApplied:CapitalMarketsAuditAction::InstrumentPoolRejected,
                CapitalMarketsAuditResourceType::InstrumentPoolChange,(string)$id,
                ['status'=>'PENDING'],['status'=>$new,...$result],$correlationId);
            return ['id'=>$id,'status'=>$new,...$result];
        });
    }

    /** @param array<string,mixed> $change @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function apply(string $org,int $actor,array $change,array $row,array $payload,string $correlation):void
    {
        $venue=(string)$change['venue_id'];
        $symbol=(string)$change['venue_symbol'];
        $id=(string)$payload['instrument_id'];
        $current=$this->mapping($org,$venue,$symbol);
        if($change['action']==='STATUS'){
            if(!is_array($current)||$current['instrument_id']!==$id
                ||$current['status']!==$row['mapping_status']){
                throw new DomainException('Canonical mapping changed before approval.');
            }
            $target=(string)$payload['target_status'];
            $stmt=$this->pdo->prepare(
                'UPDATE tn_capital_market_venue_instruments
                 SET status=:next,updated_at=UTC_TIMESTAMP(6)
                 WHERE organization_id=:org AND venue_id=:venue AND venue_symbol=:symbol
                   AND instrument_id=:id AND status=:previous'
            );
            $stmt->execute([
                'next'=>$target,'org'=>$org,'venue'=>$venue,'symbol'=>$symbol,
                'id'=>$id,'previous'=>$current['status'],
            ]);
            if($stmt->rowCount()!==1)throw new DomainException('Mapping compare-and-swap failed.');
            if($target==='SUSPENDED'){
                // Revoking the mapping must also revoke existing decision-grade
                // market evidence and active subscriptions before commit.
                $invalidate=$this->pdo->prepare(
                    "UPDATE tn_capital_market_states
                     SET state_json=JSON_SET(state_json,
                         '$.trust_status','UNTRUSTED',
                         '$.quality_status','UNTRUSTED',
                         '$.quality_score',0,
                         '$.market_status','CLOSED',
                         '$.quality_flags',JSON_ARRAY('SOURCE_DEGRADED'),
                         '$.state_version',state_version+1,
                         '$.updated_at',DATE_FORMAT(UTC_TIMESTAMP(6),'%Y-%m-%dT%H:%i:%s+00:00')),
                         trust_status='UNTRUSTED',quality_score=0,
                         state_version=state_version+1,updated_at=UTC_TIMESTAMP(6)
                     WHERE organization_id=:org AND venue_id=:venue AND instrument_id=:id"
                );
                $invalidate->execute(['org'=>$org,'venue'=>$venue,'id'=>$id]);
                $disable=$this->pdo->prepare(
                    "UPDATE tn_capital_market_subscriptions
                     SET status='DISABLED',updated_at=UTC_TIMESTAMP(6)
                     WHERE organization_id=:org AND venue_id=:venue AND instrument_id=:id
                       AND status IN ('ACTIVE','DEGRADED','PENDING')"
                );
                $disable->execute(['org'=>$org,'venue'=>$venue,'id'=>$id]);
            }
            $this->audit->record($org,$actor,CapitalMarketsAuditAction::InstrumentPoolMappingStatusChanged,
                CapitalMarketsAuditResourceType::VenueInstrument,$venue.'|'.$id,
                ['status'=>$current['status']],['status'=>$target,'provider'=>$change['provider'],
                    'snapshot_id'=>(int)$change['snapshot_id']],$correlation);
            return;
        }
        if($current!==null)throw new DomainException('Venue symbol now has a mapping.');
        $check=$this->pdo->prepare(
            'SELECT instrument_id FROM tn_capital_market_venue_instruments
             WHERE organization_id=:org AND venue_id=:venue AND instrument_id=:id FOR UPDATE'
        );
        $check->execute(['org'=>$org,'venue'=>$venue,'id'=>$id]);
        if($check->fetchColumn()!==false)throw new DomainException('Instrument already mapped to venue.');
        if($change['action']==='CREATE'){
            $this->foundation->createInstrument(new CreateInstrument(
                $org,$actor,$correlation,[
                    'id'=>$id,'symbol'=>$payload['symbol'],'canonical_symbol'=>$payload['canonical_symbol'],
                    'name'=>$payload['name'],'family'=>$payload['family'],'status'=>'ACTIVE',
                    'metadata'=>['pool_import'=>[
                        'provider'=>$change['provider'],'snapshot_id'=>(int)$change['snapshot_id'],
                        'venue_symbol'=>$symbol,'reviewed_by'=>$actor,
                    ]],
                ]
            ));
        }
        $this->foundation->registerVenueInstrument(new RegisterVenueInstrument(
            $org,$actor,$correlation,$venue,[
                'instrument_id'=>$id,'venue_symbol'=>$symbol,
                'status'=>'SUSPENDED','price_precision'=>8,'quantity_precision'=>8,
                'metadata'=>[
                    'pool_discovery_provider'=>$change['provider'],
                    'pool_snapshot_id'=>(int)$change['snapshot_id'],
                    'pool_evidence_sha256'=>$change['evidence_digest'],
                    'approval_actor_id'=>$actor,
                ],
            ]
        ));
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $row @param array<string,mixed> $input */
    private function assertProposable(string $org,array $snapshot,array $row,string $action,array $input):void
    {
        $obs=$row['observation']??null;
        if($action==='CREATE'||$action==='LINK'){
            if($obs!=='UNMAPPED_REVIEW')throw new DomainException('Only observed unlinked symbols can be imported.');
            if($action==='LINK'){
                $id=$this->text($input['instrument_id']??null,190);
                $q=$this->pdo->prepare(
                    'SELECT status FROM tn_capital_market_instruments
                     WHERE organization_id=:org AND instrument_id=:id'
                );
                $q->execute(['org'=>$org,'id'=>$id]);
                if($q->fetchColumn()!=='ACTIVE')throw new DomainException('LINK requires an existing active canonical instrument.');
            }
            return;
        }
        if($action!=='STATUS')throw new DomainException('Unsupported pool change.');
        $next=$this->literal($input['target_status']??null,['ACTIVE','SUSPENDED']);
        if($next==='ACTIVE'){
            if($obs!=='MAPPED_STATUS_REVIEW'||$row['mapping_status']!=='SUSPENDED'){
                throw new DomainException('Activation requires an observed suspended venue mapping.');
            }
        }else{
            if($obs!=='MAPPED_NOT_OBSERVED_REVIEW'||$row['mapping_status']!=='ACTIVE'){
                throw new DomainException('Suspension requires a missing active venue mapping.');
            }
            $history=$this->snapshots->history($org,$snapshot['provider'],$snapshot['venue_id'],3);
            $review=$this->reviewer->review($history,3);
            $found=false;
            foreach($review['rows'] as $candidate){
                if(($candidate['venue_symbol']??null)===$row['venue_symbol']
                    &&($candidate['instrument_id']??null)===$row['instrument_id']
                    &&($candidate['decision']??null)==='OPERATOR_REVIEW_REQUIRED')$found=true;
            }
            if(!$found)throw new DomainException('Three consecutive healthy full-mapping observations required.');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(string $org,int $id,bool $mustBeFresh=true):array
    {
        $stmt=$this->pdo->prepare(
            'SELECT provider,venue_id,observed_at,health,evidence_sha256,results_json
             FROM tn_capital_market_instrument_pool_snapshots
             WHERE organization_id=:org AND id=:id'
        );
        $stmt->execute(['org'=>$org,'id'=>$id]);
        $raw=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($raw))throw new DomainException('Scoped provider evidence not found.');
        $result=json_decode((string)$raw['results_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($result)||($raw['health']??null)!=='AVAILABLE'
            ||($result['health']??null)!=='AVAILABLE'
            ||($raw['evidence_sha256']??null)!==($result['evidence_sha256']??null)
            ||!is_string($result['provider']??null)||!is_string($result['venue_id']??null)
            ||$result['provider']!==$raw['provider']||$result['venue_id']!==$raw['venue_id']){
            throw new DomainException('Provider evidence is unavailable or inconsistent.');
        }
        $time=strtotime((string)$raw['observed_at'].' UTC');
        if($mustBeFresh&&($time===false||time()-$time>21600||$time>time()+120)){
            throw new DomainException('Provider evidence older than 6 hours.');
        }
        $result['snapshot_id']=$id;
        return $result;
    }

    /** @return array<string,mixed> */
    private function latestHealthy(string $org,string $provider,string $venue):array
    {
        $stmt=$this->pdo->prepare(
            'SELECT id FROM tn_capital_market_instrument_pool_snapshots
             WHERE organization_id=:org AND provider=:provider AND venue_id=:venue
             ORDER BY observed_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute(['org'=>$org,'provider'=>$provider,'venue'=>$venue]);
        $id=$stmt->fetchColumn();
        if($id===false)throw new DomainException('No latest provider evidence.');
        return $this->snapshot($org,(int)$id);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function observedRow(array $snapshot,string $symbol):array
    {
        $rows=$snapshot['rows']??null;
        if(!is_array($rows)||count($rows)>500)throw new DomainException('Provider evidence rows invalid.');
        foreach($rows as $row){
            if(is_array($row)&&($row['venue_symbol']??null)===$symbol){
                if(($snapshot['truncated']??false)===true
                    &&($row['instrument_id']??null)!==null
                    &&($snapshot['mapped_coverage_complete']??false)!==true){
                    throw new DomainException('Mapped symbol evidence was truncated.');
                }
                return $row;
            }
        }
        throw new DomainException('Symbol absent from bounded snapshot; cannot infer delisting.');
    }

    /** @return array<string,mixed>|null */
    private function mapping(string $org,string $venue,string $symbol):?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT instrument_id,status FROM tn_capital_market_venue_instruments
             WHERE organization_id=:org AND venue_id=:venue AND venue_symbol=:symbol FOR UPDATE'
        );
        $stmt->execute(['org'=>$org,'venue'=>$venue,'symbol'=>$symbol]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @param array<string,mixed> $row */
    private function digest(array $row):string
    {
        return hash('sha256',json_encode([
            'venue_symbol'=>$row['venue_symbol']??null,
            'instrument_id'=>$row['instrument_id']??null,
            'mapping_status'=>$row['mapping_status']??null,
            'observation'=>$row['observation']??null,
            'base'=>$row['base']??null,'quote'=>$row['quote']??null,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private function allow(string $org,int $actor,string $capability):void
    {
        if($org===''||trim($org)!==$org||strlen($org)>190
            ||$actor<1||!$this->access->hasCapability($org,$actor,$capability)){
            throw new DomainException('Instrument Pool operator capability required.');
        }
    }
    private function positive(mixed $value):int
    {
        if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new DomainException('Positive ID required.');
        $id=(int)$value;
        if($id<1)throw new DomainException('Positive ID required.');
        return $id;
    }
    private function text(mixed $value,int $limit):string
    {
        if(!is_string($value)||$value===''||trim($value)!==$value||mb_strlen($value)>$limit){
            throw new DomainException('Invalid or missing explicit import field.');
        }
        return $value;
    }
    private function reason(mixed $value):string
    {
        $value=$this->text($value,500);
        if(mb_strlen($value)<12)throw new DomainException('A review reason of at least 12 characters is required.');
        return $value;
    }
    /** @param list<string> $allowed */
    private function literal(mixed $value,array $allowed):string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))throw new DomainException('Unsupported Instrument Pool transition.');
        return $value;
    }
}
