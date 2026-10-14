<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Service\CrossVenuePoolProjection;
use PDO;
use RuntimeException;

final readonly class CrossVenuePoolCandidateRepository
{
    public function __construct(private PDO $connection, private CrossVenuePoolProjection $projection) {}

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
              underlying_symbol=VALUES(underlying_symbol),venue_symbol=VALUES(venue_symbol),
              quote_currency=VALUES(quote_currency),listing_state=VALUES(listing_state),
              source_as_of=VALUES(source_as_of),observed_at=VALUES(observed_at)'
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
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
        return count($rows);
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
