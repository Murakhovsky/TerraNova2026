<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\PortfolioValuationSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use PDO;

final readonly class MysqlPortfolioValuationSnapshotRepository implements PortfolioValuationSnapshotRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function append(string $organizationId,string $portfolioId,array $snapshot):void
    {
        $id=trim((string)($snapshot['snapshot_id']??''));
        $stamp=(string)($snapshot['valued_at']??'');
        $currency=(string)($snapshot['currency']??'');
        if ($organizationId===''||$portfolioId===''||$id===''||$stamp===''||$currency===''||strlen($id)>190) {
            throw new InvalidArgumentException('Portfolio valuation snapshot identity, time and currency are mandatory.');
        }
        if (($snapshot['valuation_status']??null)!=='COMPLETE'
            || ($snapshot['ledger_reconciled']??false)!==true
            || ($snapshot['marks_reconciled']??false)!==true
            || ($snapshot['external_flows_reconciled']??false)!==true
            || trim((string)($snapshot['provenance_id']??''))===''
            || !self::validDigest($snapshot['ledger_fingerprint']??null)
            || !self::validDigest($snapshot['marks_fingerprint']??null)
            || !self::validDigest($snapshot['external_flows_fingerprint']??null)) {
            throw new InvalidArgumentException('Only fully reconciled, provenance-linked NAV may be persisted.');
        }
        $equity=Decimal::fromString((string)($snapshot['equity']??''));
        $external=Decimal::fromString((string)($snapshot['cumulative_external_net_flow']??''));
        if ($equity->isNegative()) throw new InvalidArgumentException('Valuation equity must not be negative.');
        $time=(new DateTimeImmutable($stamp,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        if ($time>new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Future portfolio NAV cannot be persisted.');
        }
        $record=[
            'snapshot_id'=>$id,
            'valued_at'=>$time->format(DATE_ATOM),
            'currency'=>strtoupper(trim($currency)),
            'equity'=>$equity->value(),
            'cumulative_external_net_flow'=>$external->value(),
            'valuation_status'=>'COMPLETE',
            'ledger_reconciled'=>true,
            'marks_reconciled'=>true,
            'external_flows_reconciled'=>true,
            'provenance_id'=>(string)$snapshot['provenance_id'],
            'ledger_fingerprint'=>$snapshot['ledger_fingerprint'],
            'marks_fingerprint'=>$snapshot['marks_fingerprint'],
            'external_flows_fingerprint'=>$snapshot['external_flows_fingerprint'],
        ];
        $json=json_encode($record,JSON_THROW_ON_ERROR);
        $stmt=$this->connection->prepare(
            'INSERT INTO tn_capital_market_portfolio_valuation_snapshots
             (organization_id,snapshot_id,portfolio_id,valued_at,currency,record_json)
             VALUES (:org,:id,:portfolio,:valued,:currency,:record)'
        );
        // Unique identity: never overwrite accounting history via an "upsert".
        $stmt->execute([
            'org'=>$organizationId,'id'=>$id,'portfolio'=>$portfolioId,
            'valued'=>$time->format('Y-m-d H:i:s.u'),'currency'=>$record['currency'],'record'=>$json,
        ]);
    }

    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        $stmt=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_portfolio_valuation_snapshots
             WHERE organization_id=:org AND portfolio_id=:portfolio
               AND valued_at>=:from_time AND valued_at<=:to_time
             ORDER BY valued_at DESC,id DESC LIMIT 5001'
        );
        $stmt->execute([
            'org'=>$organizationId,'portfolio'=>$portfolioId,
            'from_time'=>$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'to_time'=>$to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
        $rows=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows)>5000) return []; // Never project a truncated valuation history.
        $out=[];
        foreach (array_reverse($rows) as $raw) {
            $value=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
            if (is_array($value)) $out[]=$value;
        }
        return $out;
    }
    private static function validDigest(mixed $value):bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value)===1;
    }

}
