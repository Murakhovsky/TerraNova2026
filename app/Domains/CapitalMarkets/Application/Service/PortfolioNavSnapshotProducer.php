<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\PortfolioValuationSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

/**
 * A guarded writer, not an estimation engine. Only verified cash, asset marks,
 * liabilities and reconciled external ledger flows may enter NAV.
 */
final readonly class PortfolioNavSnapshotProducer
{
    public function __construct(private PortfolioValuationSnapshotRepositoryInterface $snapshots) {}

    /**
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public function record(string $organizationId,string $portfolioId,array $evidence): array
    {
        if ($organizationId === '' || $portfolioId === '') {
            throw new InvalidArgumentException('Tenant and portfolio required.');
        }
        foreach (['snapshot_id','valued_at','currency','provenance_id','ledger_fingerprint','marks_fingerprint','external_flows_fingerprint'] as $key) {
            if (!is_string($evidence[$key] ?? null) || trim($evidence[$key]) === '') {
                throw new InvalidArgumentException('Missing NAV evidence: '.$key);
            }
        }
        foreach (['ledger_reconciled','marks_reconciled','external_flows_reconciled'] as $gate) {
            if (($evidence[$gate] ?? false) !== true) {
                throw new InvalidArgumentException('NAV reconciliation incomplete: '.$gate);
            }
        }
        if (!is_array($evidence['cash_by_currency'] ?? null)
            || !is_array($evidence['marked_positions'] ?? null)
            || !is_array($evidence['liabilities_by_currency'] ?? null)
            || !is_array($evidence['external_flows_by_currency'] ?? null)) {
            throw new InvalidArgumentException('Explicit cash, marks, liabilities and flow ledgers are required.');
        }
        $currency=strtoupper(trim($evidence['currency']));
        $cash=$this->sumCurrency($evidence['cash_by_currency'],$currency);
        $liabilities=$this->sumCurrency($evidence['liabilities_by_currency'],$currency);
        $flows=$this->sumCurrency($evidence['external_flows_by_currency'],$currency);
        $marks=Decimal::fromString('0');
        foreach ($evidence['marked_positions'] as $position) {
            if (!is_array($position)
                || ($position['quote_currency'] ?? '') !== $currency
                || ($position['mark_reconciled'] ?? false) !== true
                || !is_string($position['market_value'] ?? null)
                || !is_string($position['mark_source_fingerprint'] ?? null)
                || trim($position['mark_source_fingerprint']) === ''
                || !is_string($position['position_id'] ?? null)
            ) {
                throw new InvalidArgumentException('Every position needs a reconciled current mark and provenance in NAV currency.');
            }
            $marks=DecimalMath::add($marks,Decimal::fromString($position['market_value']));
        }
        $nav=DecimalMath::subtract(DecimalMath::add($cash,$marks),$liabilities);
        if ($nav->isNegative()) {
            throw new InvalidArgumentException('Negative NAV is unsupported by this valuation policy.');
        }
        $valuedAt=(new DateTimeImmutable($evidence['valued_at'],new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $snapshot=[
            'snapshot_id'=>$evidence['snapshot_id'],
            'valued_at'=>$valuedAt->format(DATE_ATOM),
            'currency'=>$currency,
            'equity'=>$nav->value(),
            'cumulative_external_net_flow'=>$flows->value(),
            'valuation_status'=>'COMPLETE',
            'ledger_reconciled'=>true,
            'marks_reconciled'=>true,
            'external_flows_reconciled'=>true,
            'provenance_id'=>$evidence['provenance_id'],
        ];
        $this->snapshots->append($organizationId,$portfolioId,$snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $values */
    private function sumCurrency(array $values,string $currency):Decimal
    {
        $sum=Decimal::fromString('0');
        foreach ($values as $record) {
            if (!is_array($record)
                || strtoupper((string)($record['currency'] ?? '')) !== $currency
                || !is_string($record['amount'] ?? null)) {
                throw new InvalidArgumentException('NAV basket contains an unconverted or unsupported currency.');
            }
            $sum=DecimalMath::add($sum,Decimal::fromString($record['amount']));
        }
        return $sum;
    }
}
