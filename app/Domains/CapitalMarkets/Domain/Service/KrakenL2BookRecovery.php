<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;

/**
 * Kraken WS v2 raw-decimal order book. Snapshot and update frames must be
 * dispatched serially per symbol. CRC is checked before state publication.
 * Never convert price or quantity to floating point.
 */
final class KrakenL2BookRecovery
{
    /** @var array<string,array{price:string,qty:string}> */
    private array $bids = [];
    /** @var array<string,array{price:string,qty:string}> */
    private array $asks = [];
    private bool $trusted = false;
    private int $generation = 0;
    private int $version = 0;
    private ?string $failure = null;

    public function __construct(private readonly KrakenL2Checksum $checksum, private readonly int $depth = 10)
    {
        if ($depth < 10) throw new DomainException('Kraken checksum requires at least ten book levels.');
    }

    public function begin(): int
    {
        ++$this->generation;
        $this->trusted = false;
        $this->bids = [];
        $this->asks = [];
        $this->failure = null;
        return $this->generation;
    }

    /**
     * @param list<array{price:string,qty:string}> $bids
     * @param list<array{price:string,qty:string}> $asks
     */
    public function snapshot(int $generation, array $bids, array $asks, string $crc): void
    {
        $this->assertGeneration($generation);
        $this->trusted = false;
        $this->bids = [];
        $this->asks = [];
        $this->updateSide($this->bids, $bids);
        $this->updateSide($this->asks, $asks);
        $this->verify($crc);
    }

    /**
     * @param list<array{price:string,qty:string}> $bids
     * @param list<array{price:string,qty:string}> $asks
     */
    public function delta(int $generation, array $bids, array $asks, string $crc): void
    {
        $this->assertGeneration($generation);
        if (!$this->trusted) throw new DomainException('Kraken book requires verified snapshot.');
        $this->trusted = false;
        $this->updateSide($this->bids, $bids);
        $this->updateSide($this->asks, $asks);
        $this->verify($crc);
    }

    /** @return array{bids:list<array{price:string,qty:string}>,asks:list<array{price:string,qty:string}>,version:int}|null */
    public function trustedBook(int $generation): ?array
    {
        if ($generation !== $this->generation || !$this->trusted) return null;
        return ['bids' => $this->sorted($this->bids, true), 'asks' => $this->sorted($this->asks, false), 'version' => $this->version];
    }

    /** @return array{trusted:bool,generation:int,version:int,failure:?string} */
    public function diagnostics(): array
    {
        return ['trusted' => $this->trusted, 'generation' => $this->generation, 'version' => $this->version, 'failure' => $this->failure];
    }

    /** @param array<string,array{price:string,qty:string}> $side @param list<array{price:string,qty:string}> $changes */
    private function updateSide(array &$side, array $changes): void
    {
        foreach ($changes as $row) {
            $price = $row['price'];
            $qty = $row['qty'];
            if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $price)
                || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $qty)
                || preg_match('/^0+(?:\.0+)?$/D', $price)) {
                $this->invalidate('INVALID_LEVEL');
                throw new DomainException('Invalid Kraken book level.');
            }
            $key = $this->normalize($price);
            if (preg_match('/^0+(?:\.0+)?$/D', $qty)) unset($side[$key]);
            else $side[$key] = ['price' => $price, 'qty' => $qty];
        }
    }

    private function normalize(string $decimal): string
    {
        $parts = explode('.', $decimal, 2);
        $whole = ltrim($parts[0], '0') ?: '0';
        $fraction = rtrim($parts[1] ?? '', '0');
        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    /** @param array<string,array{price:string,qty:string}> $side @return list<array{price:string,qty:string}> */
    private function sorted(array $side, bool $descending): array
    {
        $levels = array_values($side);
        usort($levels, static function (array $a, array $b) use ($descending): int {
            [$ai, $af] = array_pad(explode('.', $a['price'], 2), 2, '');
            [$bi, $bf] = array_pad(explode('.', $b['price'], 2), 2, '');
            $ai = ltrim($ai, '0') ?: '0';
            $bi = ltrim($bi, '0') ?: '0';
            $cmp = strlen($ai) <=> strlen($bi);
            if ($cmp === 0) $cmp = strcmp($ai, $bi);
            if ($cmp === 0) $cmp = strcmp(str_pad($af, max(strlen($af), strlen($bf)), '0'), str_pad($bf, max(strlen($af), strlen($bf)), '0'));
            return $descending ? -$cmp : $cmp;
        });
        return array_slice($levels, 0, $this->depth);
    }

    private function verify(string $crc): void
    {
        try {
            $bids = $this->sorted($this->bids, true);
            $asks = $this->sorted($this->asks, false);
            if ($bids !== [] && $asks !== [] && $this->normalize($bids[0]['price']) === $this->normalize($asks[0]['price'])) {
                throw new DomainException('Crossed Kraken book.');
            }
            $this->checksum->assertMatches($asks, $bids, $crc);
            $this->trusted = true;
            ++$this->version;
        } catch (\Throwable $e) {
            $this->invalidate('CHECKSUM_OR_BOOK_INVALID');
            throw $e;
        }
    }

    private function invalidate(string $reason): void
    {
        $this->trusted = false;
        $this->failure = $reason;
        $this->bids = [];
        $this->asks = [];
    }

    private function assertGeneration(int $generation): void
    {
        if ($generation !== $this->generation) throw new DomainException('Stale Kraken session generation.');
    }
}
