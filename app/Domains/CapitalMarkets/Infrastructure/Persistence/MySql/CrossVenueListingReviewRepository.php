<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Tenant-scoped, transactionally fenced review of a public venue LISTING only. */
final readonly class CrossVenueListingReviewRepository
{
    private const POOL='BINANCE_BSTOCKS_SEED';
    private const HOSTS=['BINANCE'=>'www.binance.com','BYBIT'=>'www.bybit.com','KRAKEN'=>'www.kraken.com'];

    public function __construct(private PDO $connection,private CrossVenueBboAdmissionFence $fence) {}

    /** @return list<array<string,mixed>> */
    public function pending(string $organizationId,int $limit=40,int $offset=0):array
    {
        if ($organizationId==='' || strlen($organizationId)>190 || $limit<1 || $limit>100
            || $offset<0 || $offset>1480) {
            throw new InvalidArgumentException('Invalid listing review scope.');
        }
        $statement=$this->connection->prepare(
            "SELECT seed_token,underlying_symbol,venue,venue_symbol,quote_currency,source_as_of,
                    verification_state,economic_equivalence,listing_state
             FROM tn_capital_market_cross_venue_pool_candidates
             WHERE organization_id=:org AND pool_key=:pool
               AND listing_state='LISTED_EXACT_TOKEN_SYMBOL'
             ORDER BY CASE verification_state WHEN 'UNVERIFIED' THEN 0 ELSE 1 END,
                      seed_token,venue LIMIT ".$limit." OFFSET ".$offset
        );
        $statement->execute(['org'=>$organizationId,'pool'=>self::POOL]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Immediate fail-closed revocation even if Discovery is stale or unavailable. */
    public function revoke(string $org,int $actor,string $token,string $venue,string $reason):array
    {
        $reason=trim($reason);
        if ($org==='' || strlen($org)>190 || $actor<1
            || preg_match('/^[A-Z0-9]{2,40}$/D',$token)!==1
            || !array_key_exists($venue,self::HOSTS)
            || mb_strlen($reason)<10 || mb_strlen($reason)>500) {
            throw new InvalidArgumentException('Revocation requires tenant, reviewer and an explanatory reason.');
        }
        if ($this->connection->inTransaction()) throw new RuntimeException('Revocation requires standalone transaction.');
        $this->connection->beginTransaction();
        try {
            $find=$this->connection->prepare(
                'SELECT venue_symbol,quote_currency,source_as_of
                 FROM tn_capital_market_cross_venue_pool_candidates
                 WHERE organization_id=:org AND pool_key=:pool AND seed_token=:token AND venue=:venue FOR UPDATE'
            );
            $find->execute(['org'=>$org,'pool'=>self::POOL,'token'=>$token,'venue'=>$venue]);
            $row=$find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new RuntimeException('Listing candidate not found.');
            $this->connection->prepare(
                'UPDATE tn_capital_market_cross_venue_pool_candidates
                 SET verification_state=\'UNVERIFIED\'
                 WHERE organization_id=:org AND pool_key=:pool AND seed_token=:token AND venue=:venue'
            )->execute(['org'=>$org,'pool'=>self::POOL,'token'=>$token,'venue'=>$venue]);
            $disabled=$this->fence->disableForCandidate($org,$token,$venue);
            $history=$this->connection->prepare(
                'INSERT INTO tn_capital_market_pool_listing_reviews
                 (organization_id,pool_key,seed_token,venue,venue_symbol,quote_currency,source_as_of,
                  reviewer_actor_id,decision,evidence_url,evidence_sha256,reviewed_at)
                 VALUES (:org,:pool,:token,:venue,:symbol,:quote,:as_of,:actor,
                     \'REVOKE_LISTING\',\'operator://manual-revocation\',:hash,UTC_TIMESTAMP(6))'
            );
            $history->execute(['org'=>$org,'pool'=>self::POOL,'token'=>$token,'venue'=>$venue,
                'symbol'=>$row['venue_symbol']??'','quote'=>$row['quote_currency']??'',
                'as_of'=>$row['source_as_of'],'actor'=>$actor,
                'hash'=>hash('sha256',$reason)]);
            $this->connection->commit();
            return ['status'=>'UNVERIFIED','seed_token'=>$token,'venue'=>$venue,
                'disabled_subscriptions'=>$disabled,'economic_equivalence'=>'NOT_ESTABLISHED'];
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * The source_as_of value is a compare-and-swap token from the operator's
     * screen: older reviews never approve newer discovery observations.
     * Decision is limited to symbol monitoring, never economic equivalence.
     */
    public function decide(
        string $org,int $actor,string $token,string $venue,string $expectedSourceAsOf,
        string $decision,string $evidenceUrl,string $evidenceSha256
    ):array {
        if ($org==='' || strlen($org)>190 || $actor<1
            || preg_match('/^[A-Z0-9]{2,40}$/D',$token)!==1
            || !array_key_exists($venue,self::HOSTS)
            || $decision!=='APPROVE_LISTING'
            || preg_match('/^[a-f0-9]{64}$/D',$evidenceSha256)!==1
            || strlen($expectedSourceAsOf)>40) {
            throw new InvalidArgumentException('Invalid listing review request.');
        }
        $url=parse_url($evidenceUrl);
        if (!is_array($url) || ($url['scheme']??null)!=='https'
            || strtolower((string)($url['host']??''))!==self::HOSTS[$venue]
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || isset($url['port']) || strlen($evidenceUrl)>512) {
            throw new InvalidArgumentException('Listing evidence URL must be official venue HTTPS.');
        }
        if ($this->connection->inTransaction()) throw new RuntimeException('Listing review requires a new transaction.');
        $this->connection->beginTransaction();
        try {
            $find=$this->connection->prepare(
                'SELECT seed_token,venue,venue_symbol,quote_currency,source_as_of,
                        listing_state,verification_state,economic_equivalence
                 FROM tn_capital_market_cross_venue_pool_candidates
                 WHERE organization_id=:org AND pool_key=:pool AND seed_token=:token AND venue=:venue
                 FOR UPDATE'
            );
            $find->execute(['org'=>$org,'pool'=>self::POOL,'token'=>$token,'venue'=>$venue]);
            $row=$find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['source_as_of']!==$expectedSourceAsOf) {
                throw new RuntimeException('Discovery source changed; reload listing review.');
            }
            if ($row['listing_state']!=='LISTED_EXACT_TOKEN_SYMBOL'
                || !is_string($row['venue_symbol']) || $row['venue_symbol']===''
                || !in_array($row['quote_currency'],['USD','USDT'],true)) {
                throw new RuntimeException('Only exact currently listed symbols are reviewable.');
            }
            $stamp=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$row['source_as_of'],
                new DateTimeZone('UTC'));
            if (!$stamp || $stamp->getTimestamp()>time()+120
                || $stamp->getTimestamp()<time()-172800) {
                throw new RuntimeException('Listing discovery is stale or from the future.');
            }
            $state=$decision==='APPROVE_LISTING'?'REVIEWED_LISTING':'UNVERIFIED';
            $update=$this->connection->prepare(
                'UPDATE tn_capital_market_cross_venue_pool_candidates
                 SET verification_state=:state
                 WHERE organization_id=:org AND pool_key=:pool AND seed_token=:token AND venue=:venue
                   AND source_as_of=:source AND listing_state=:listed'
            );
            $update->execute(['state'=>$state,'org'=>$org,'pool'=>self::POOL,'token'=>$token,
                'venue'=>$venue,'source'=>$expectedSourceAsOf,'listed'=>'LISTED_EXACT_TOKEN_SYMBOL']);
            if ($update->rowCount()===0 && $row['verification_state']!==$state) {
                throw new RuntimeException('Listing review compare-and-swap failed.');
            }
            $history=$this->connection->prepare(
                'INSERT INTO tn_capital_market_pool_listing_reviews
                 (organization_id,pool_key,seed_token,venue,venue_symbol,quote_currency,source_as_of,
                  reviewer_actor_id,decision,evidence_url,evidence_sha256,reviewed_at)
                 VALUES (:org,:pool,:token,:venue,:symbol,:quote,:as_of,:actor,:decision,:url,:hash,UTC_TIMESTAMP(6))'
            );
            $history->execute(['org'=>$org,'pool'=>self::POOL,'token'=>$token,'venue'=>$venue,
                'symbol'=>$row['venue_symbol'],'quote'=>$row['quote_currency'],'as_of'=>$expectedSourceAsOf,
                'actor'=>$actor,'decision'=>$decision,'url'=>$evidenceUrl,'hash'=>$evidenceSha256]);
            $this->connection->commit();
            return ['status'=>$state,'seed_token'=>$token,'venue'=>$venue,
                'venue_symbol'=>$row['venue_symbol'],'economic_equivalence'=>'NOT_ESTABLISHED'];
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $e;
        }
    }
}
