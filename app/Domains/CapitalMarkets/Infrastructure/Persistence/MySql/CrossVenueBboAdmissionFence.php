<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Fails closed for previously persisted BBO intents when human listing
 * authority was revoked, drifted or expired. Reuses the canonical subscription
 * table; this is NOT a second market-data worker or ingestion path.
 */
final readonly class CrossVenueBboAdmissionFence
{
    public function __construct(private PDO $connection) {}

    public function disableForCandidate(string $org,string $token,string $venue):int
    {
        $this->validate($org);
        if (preg_match('/^[A-Z0-9]{2,40}$/D',$token)!==1
            || !in_array($venue,['BINANCE','BYBIT','KRAKEN'],true)) {
            throw new InvalidArgumentException('Invalid BBO revocation identity.');
        }
        $update=$this->connection->prepare(
            'UPDATE tn_capital_market_subscriptions s
             INNER JOIN tn_capital_market_instrument_identifiers i
                 ON i.organization_id=s.organization_id AND i.instrument_id=s.instrument_id
                 AND i.identifier_type=\'PROVIDER_ID\'
                 AND i.identifier_source=\'BINANCE_BSTOCKS_SEED\'
                 AND i.identifier_value=:token
             INNER JOIN tn_capital_market_venues v
                 ON v.organization_id=s.organization_id AND v.venue_id=s.venue_id
             SET s.status=\'DISABLED\'
             WHERE s.organization_id=:org AND s.status=\'ACTIVE\' AND s.data_type=\'BBO\'
               AND (v.code=:venue OR v.code=:spot)'
        );
        $update->execute(['org'=>$org,'token'=>$token,'venue'=>$venue,'spot'=>$venue.'_SPOT']);
        return $update->rowCount();
    }

    public function disableUnapproved(string $org):int
    {
        $this->validate($org);
        $freshAfter=(new DateTimeImmutable('-48 hours',new DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
        $update=$this->connection->prepare(
            'UPDATE tn_capital_market_subscriptions s
             INNER JOIN tn_capital_market_instrument_identifiers i
                 ON i.organization_id=s.organization_id AND i.instrument_id=s.instrument_id
                 AND i.identifier_type=\'PROVIDER_ID\'
                 AND i.identifier_source=\'BINANCE_BSTOCKS_SEED\'
             INNER JOIN tn_capital_market_venues v
                 ON v.organization_id=s.organization_id AND v.venue_id=s.venue_id
             INNER JOIN tn_capital_market_cross_venue_pool_candidates c
                 ON c.organization_id=s.organization_id
                 AND c.pool_key=\'BINANCE_BSTOCKS_SEED\'
                 AND c.seed_token=i.identifier_value
                 AND (v.code=c.venue OR v.code=CONCAT(c.venue,\'_SPOT\'))
             SET s.status=\'DISABLED\'
             WHERE s.organization_id=:org AND s.status=\'ACTIVE\' AND s.data_type=\'BBO\'
               AND (c.verification_state<>\'REVIEWED_LISTING\'
                    OR c.listing_state<>\'LISTED_EXACT_TOKEN_SYMBOL\'
                    OR c.source_as_of<:fresh_after)'
        );
        $update->execute(['org'=>$org,'fresh_after'=>$freshAfter]);
        return $update->rowCount();
    }

    private function validate(string $org):void
    {
        if ($org==='' || strlen($org)>190) throw new InvalidArgumentException('Tenant scope required.');
    }
}
