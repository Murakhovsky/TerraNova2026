<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use InvalidArgumentException;

/**
 * Historical watchlist candidate input, never an authoritative instrument graph.
 * The workbook's TRADING status is explicitly not interpreted as current.
 */
final class CrossVenueUniverse
{
    /** @return list<array{underlying:string,token:string,market:string,asset_type:string,leverage:string,risk_class:string}> */
    public function load(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) throw new InvalidArgumentException('Cross-venue universe file missing.');
        try {
            $header = fgetcsv($handle);
            if ($header !== ['underlying_symbol','token_symbol','binance_spot_symbol','source_asset_type','leverage_factor']) {
                throw new InvalidArgumentException('Cross-venue universe has an unsupported schema.');
            }
            $rows = [];
            $symbols = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== 5) throw new InvalidArgumentException('Cross-venue universe has a malformed row.');
                [$underlying,$token,$market,$family,$leverage] = $row;
                if (preg_match('/^[A-Z0-9]{1,15}$/D', $underlying) !== 1
                    || preg_match('/^[A-Z0-9]{2,24}$/D', $token) !== 1
                    || preg_match('/^[A-Z0-9]{4,40}$/D', $market) !== 1
                    || !in_array($family, ['Stock','ETF'], true)
                    || preg_match('/^-?[0-9]{1,2}$/D', $leverage) !== 1
                    || (int) $leverage === 0
                    || isset($symbols[$token])) {
                    throw new InvalidArgumentException('Cross-venue universe candidate is ambiguous or duplicated.');
                }
                $symbols[$token] = true;
                $rows[] = [
                    'underlying'=>$underlying, 'token'=>$token, 'market'=>$market,
                    'asset_type'=>$family, 'leverage'=>$leverage,
                    'risk_class'=>abs((int) $leverage) !== 1 || (int)$leverage < 0
                        ? 'LEVERAGED_OR_INVERSE' : 'STANDARD_CANDIDATE',
                ];
                if (count($rows) > 500) throw new InvalidArgumentException('Cross-venue universe exceeds safety limit.');
            }
            if ($rows === []) throw new InvalidArgumentException('Cross-venue universe is empty.');
            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
