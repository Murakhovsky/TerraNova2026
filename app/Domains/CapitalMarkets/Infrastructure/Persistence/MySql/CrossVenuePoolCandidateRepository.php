<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Service\CrossVenuePoolProjection;
use PDO;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class CrossVenuePoolCandidateRepository
{
    public function __construct(private PDO $connection, private CrossVenuePoolProjection $projection,
        private CrossVenueBboAdmissionFence $fence) {}

    /** Idempotent candidate synchronization; never writes canonical identity or approved relationships. */
    public function reconcile(string $organizationId, array $snapshot): int
    {
        if ($organizationId === '' || strlen($organizationId) > 190) throw new RuntimeException('Invalid tenant.');
        $rows = $this->projection->candidates($snapshot);
        $statement = $this->connection->prepare(
            'INSERT INTO tn_capital_market_cross_venue_pool_candidates
              (organization_id,pool_key,seed_token,underlying_symbol,venue,venue_symbol,quote_currency,
               listing_state,verification_state,economic_equivalence,source_as_of,observed_at)
             VALUES (:org,\'BINANCE_BSTOCKS_SEED\',:token,:underlying,:venue,:symbol,:quote,
                     :state,\'UNVERIFIED\',\'NOT_ESTABLISHED\',:source_as_of,UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
              verification_state=IF(VALUES(source_as_of)>=source_as_of,
                  IF(underlying_symbol <=> VALUES(underlying_symbol)
                     AND venue_symbol <=> VALUES(venue_symbol)
                     AND quote_currency <=> VALUES(quote_currency)
                     AND listing_state <=> VALUES(listing_state),
                     verification_state,\'UNVERIFIED\'),verification_state),
              economic_equivalence=IF(VALUES(source_as_of)>=source_as_of,
                  IF(underlying_symbol <=> VALUES(underlying_symbol)
                     AND venue_symbol <=> VALUES(venue_symbol)
                     AND quote_currency <=> VALUES(quote_currency)
                     AND listing_state <=> VALUES(listing_state),
                     economic_equivalence,\'NOT_ESTABLISHED\'),economic_equivalence),
              underlying_symbol=IF(VALUES(source_as_of)>=source_as_of,VALUES(underlying_symbol),underlying_symbol),
              venue_symbol=IF(VALUES(source_as_of)>=source_as_of,VALUES(venue_symbol),venue_symbol),
              quote_currency=IF(VALUES(source_as_of)>=source_as_of,VALUES(quote_currency),quote_currency),
              listing_state=IF(VALUES(source_as_of)>=source_as_of,VALUES(listing_state),listing_state),
              observed_at=IF(VALUES(source_as_of)>=source_as_of,VALUES(observed_at),observed_at),
              source_as_of=IF(VALUES(source_as_of)>=source_as_of,VALUES(source_as_of),source_as_of)'
        );
        $this->connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                $statement->execute([
                    'org'=>$organizationId,'token'=>$row['seed_token'],
                    'underlying'=>$row['underlying_symbol'],'venue'=>$row['venue'],
                    'symbol'=>$row['venue_symbol'],'quote'=>$row['quote_currency'],
                    'state'=>$row['listing_state'],'source_as_of'=>$row['source_as_of'],
                ]);
            }
            $this->fence->disableUnapproved($organizationId);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
        return count($rows);
    }

    /** @return list<array{seed_token:string,venue_symbol:string}> */
    public function listedForVenue(string $organizationId,string $venue,int $limit=500): array
    {
        if ($organizationId==='' || !in_array($venue,['BINANCE','BYBIT','KRAKEN'],true)) {
            throw new RuntimeException('Invalid subscription planning scope.');
        }
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT c.seed_token,c.venue_symbol
             FROM tn_capital_market_cross_venue_pool_candidates c
             INNER JOIN tn_capital_market_pool_listing_reviews r
               ON r.id=(
                 SELECT MAX(last_review.id)
                 FROM tn_capital_market_pool_listing_reviews last_review
                 WHERE last_review.organization_id=c.organization_id
                   AND last_review.pool_key=c.pool_key
                   AND last_review.seed_token=c.seed_token
                   AND last_review.venue=c.venue
               )
             WHERE c.organization_id=:org AND c.pool_key=:pool AND c.venue=:venue
               AND c.listing_state=:state AND c.verification_state=:verified
               AND c.source_as_of>=:fresh_after AND c.venue_symbol IS NOT NULL
               AND r.decision=\'APPROVE_LISTING\'
               AND r.venue_symbol=c.venue_symbol
               AND r.quote_currency=c.quote_currency
               AND r.reviewed_at>=UTC_TIMESTAMP(6)-INTERVAL 48 HOUR
             ORDER BY c.seed_token LIMIT '.$limit
        );
        $freshAfter=(new DateTimeImmutable('-48 hours',new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
        $statement->execute([
            'org'=>$organizationId,'pool'=>'BINANCE_BSTOCKS_SEED',
            'venue'=>$venue,'state'=>'LISTED_EXACT_TOKEN_SYMBOL',
            'verified'=>'REVIEWED_LISTING','fresh_after'=>$freshAfter,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function counts(string $organizationId): array
    {
        $query=$this->connection->prepare(
            'SELECT listing_state,COUNT(*) AS count FROM tn_capital_market_cross_venue_pool_candidates
             WHERE organization_id=:org AND pool_key=\'BINANCE_BSTOCKS_SEED\' GROUP BY listing_state'
        );
        $query->execute(['org'=>$organizationId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
