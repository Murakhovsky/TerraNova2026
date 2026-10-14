<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use DomainException;

/**
 * Diff-depth sequence rules. Call acceptFirst only after REST snapshot is loaded.
 * Sequence IDs are decimal strings to avoid platform integer overflow.
 */
final class BinanceL2SequenceValidator
{
    private ?string $last = null;

    public function __construct(private readonly bool $futures) {}

    public function acceptFirst(string $snapshotId, string $first, string $last): bool
    {
        $this->validate($snapshotId, $first, $last);
        if ($this->compare($last, $snapshotId) <= 0) return false;
        $next = $this->increment($snapshotId);
        if ($this->compare($first, $next) > 0 || $this->compare($last, $next) < 0) {
            throw new DomainException('Binance snapshot/delta bridge gap.');
        }
        if ($this->compare($first, $last) > 0) throw new DomainException('Inverted update ID range.');
        $this->last = $last;
        return true;
    }

    public function acceptNext(string $first, string $last, ?string $previousFinal = null): bool
    {
        if ($this->last === null) throw new DomainException('Binance sequence is not initialized.');
        $this->validate($this->last, $first, $last);
        if ($this->compare($first, $last) > 0) throw new DomainException('Inverted update ID range.');
        if ($this->compare($last, $this->last) <= 0) return false;
        if ($this->futures) {
            if ($previousFinal === null || $this->compare($previousFinal, $this->last) !== 0) {
                throw new DomainException('Binance futures pu continuity failure.');
            }
        } elseif ($this->compare($first, $this->increment($this->last)) > 0) {
            throw new DomainException('Binance spot update ID gap.');
        }
        $this->last = $last;
        return true;
    }

    public function last(): ?string { return $this->last; }

    public function reset(): void { $this->last = null; }

    private function validate(string ...$ids): void
    {
        foreach ($ids as $id) {
            if ($id === '' || !ctype_digit($id)) throw new DomainException('Invalid Binance update ID.');
        }
    }

    private function compare(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    private function increment(string $id): string
    {
        $digits = str_split(ltrim($id, '0') ?: '0');
        for ($i = count($digits) - 1; $i >= 0; --$i) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return implode('', $digits);
            }
            $digits[$i] = '0';
        }
        return '1'.implode('', $digits);
    }
}
