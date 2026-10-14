<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;

/**
 * Kraken WebSocket v2 book CRC32, top ten levels.
 * Feed raw exchange decimal strings; never pass rounded floats.
 */
final class KrakenL2Checksum
{
    /**
     * @param list<array{price:string,qty:string}> $asks ascending price
     * @param list<array{price:string,qty:string}> $bids descending price
     */
    public function calculate(array $asks, array $bids): string
    {
        $payload = '';
        foreach ([array_slice($asks, 0, 10), array_slice($bids, 0, 10)] as $side) {
            foreach ($side as $level) {
                $payload .= $this->canonical($level['price']);
                $payload .= $this->canonical($level['qty']);
            }
        }
        return sprintf('%u', crc32($payload));
    }

    /** @param list<array{price:string,qty:string}> $asks @param list<array{price:string,qty:string}> $bids */
    public function assertMatches(array $asks, array $bids, string $expected): void
    {
        if ($expected === '' || !ctype_digit($expected) || $this->calculate($asks, $bids) !== $expected) {
            throw new DomainException('Kraken L2 checksum mismatch.');
        }
    }

    private function canonical(string $decimal): string
    {
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $decimal) !== 1) {
            throw new DomainException('Invalid Kraken decimal value.');
        }
        $digits = ltrim(str_replace('.', '', $decimal), '0');
        return $digits === '' ? '0' : $digits;
    }
}
