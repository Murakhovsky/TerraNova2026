<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use InvalidArgumentException;

/** Converts discovery evidence into unverified, venue-scoped monitoring candidates. */
final class CrossVenuePoolProjection
{
    /** @return list<array<string,string|null>> */
    public function candidates(array $snapshot): array
    {
        if (($snapshot['dataset'] ?? null) !== 'cross_venue_candidate_discovery' || !is_array($snapshot['rows'] ?? null)) {
            throw new InvalidArgumentException('Invalid discovery snapshot.');
        }
        $out = [];
        $dedupe = [];
        foreach ($snapshot['rows'] as $row) {
            if (!is_array($row) || !is_array($row['candidate'] ?? null) || !is_array($row['venues'] ?? null)) {
                throw new InvalidArgumentException('Invalid discovery row.');
            }
            $seed = $row['candidate'];
            foreach ($row['venues'] as $venue) {
                if (!is_array($venue)) throw new InvalidArgumentException('Invalid venue observation.');
                $provider = (string)($venue['venue'] ?? '');
                $status = (string)($venue['status'] ?? '');
                if (!in_array($provider, ['BINANCE','BYBIT','KRAKEN'], true)) throw new InvalidArgumentException('Unknown venue.');
                if ($status === 'UNAVAILABLE') continue; // Do not erase last known facts during outages.
                if (!in_array($status, ['LISTED_EXACT_TOKEN_SYMBOL','DISTINCT_TOKEN_REVIEW_REQUIRED','NOT_LISTED'], true)) {
                    throw new InvalidArgumentException('Unknown listing state.');
                }
                $symbol = isset($venue['symbol']) ? (string)$venue['symbol'] : null;
                $key = strtoupper((string)($seed['token'] ?? '')).'|'.$provider;
                if ($key === '|'.$provider || isset($dedupe[$key])) throw new InvalidArgumentException('Duplicate/invalid candidate.');
                $dedupe[$key] = true;
                $out[] = [
                    'seed_token'=>(string)$seed['token'],
                    'underlying_symbol'=>(string)($seed['underlying'] ?? ''),
                    'venue'=>$provider,
                    'venue_symbol'=>$symbol,
                    'quote_currency'=>isset($venue['quote']) ? (string)$venue['quote'] : null,
                    'listing_state'=>$status,
                    'verification_state'=>'UNVERIFIED',
                    'economic_equivalence'=>'NOT_ESTABLISHED',
                    'source_as_of'=>(string)($snapshot['as_of_utc'] ?? ''),
                ];
            }
        }
        return $out;
    }
}
