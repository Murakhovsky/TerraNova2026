<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\PaperNavSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Append-only simulation evidence, stored separately from certified real NAV. */
final readonly class MysqlPaperNavSnapshotRepository implements PaperNavSnapshotRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function append(string $organizationId,string $portfolioId,array $snapshot):void
    {
        if (trim($organizationId)==='' || trim($portfolioId)===''
            || ($snapshot['mode']??null)!=='PAPER'
            || ($snapshot['valuation_status']??null)!=='SIMULATED'
            || ($snapshot['status']??null)!=='SIMULATED'
            || ($snapshot['certified']??null)!==false
            || ($snapshot['ledger_reconciled']??null)!==false
            || ($snapshot['external_flows_reconciled']??null)!==false
            || (string)($snapshot['portfolio_id']??'')!==$portfolioId) {
            throw new InvalidArgumentException('Paper snapshot cannot claim certified financial authority.');
        }
        $id=trim((string)($snapshot['snapshot_id']??''));
        if ($id==='' || strlen($id)>190
            || !is_string($snapshot['valued_at']??null)
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/',$snapshot['valued_at'])!==1
            || !is_string($snapshot['source_fingerprint']??null)
            || preg_match('/^[a-f0-9]{64}$/',$snapshot['source_fingerprint'])!==1) {
            throw new InvalidArgumentException('Paper snapshot identity or evidence fingerprint invalid.');
        }
        $currency=strtoupper(trim((string)($snapshot['currency']??'')));
        if ($currency==='' || strlen($currency)>32) throw new InvalidArgumentException('Missing paper valuation currency.');
        $initial=Decimal::fromString((string)($snapshot['initial_capital']??''));
        $equity=Decimal::fromString((string)($snapshot['equity']??''));
        Decimal::fromString((string)($snapshot['net_pnl']??''));
        Decimal::fromString((string)($snapshot['realized_pnl']??''));
        Decimal::fromString((string)($snapshot['unrealized_pnl']??''));
        if ($initial->isNegative() || $equity->isNegative()) throw new InvalidArgumentException('Paper equity cannot be negative.');
        $at=(new DateTimeImmutable($snapshot['valued_at']))->setTimezone(new DateTimeZone('UTC'));
        if ($at>new DateTimeImmutable('now',new DateTimeZone('UTC'))) throw new InvalidArgumentException('Paper NAV cannot be future dated.');
        $valued=$at->format('Y-m-d H:i:s.u');

        $latest=$this->connection->prepare(
            'SELECT initial_capital,currency FROM tn_capital_market_paper_nav_snapshots
             WHERE organization_id=:org AND portfolio_id=:portfolio ORDER BY valued_at DESC,id DESC LIMIT 1'
        );
        $latest->execute(['org'=>$organizationId,'portfolio'=>$portfolioId]);
        $baseline=$latest->fetch(PDO::FETCH_ASSOC);
        if (is_array($baseline) && ($currency!==(string)$baseline['currency']
            || $initial->compareTo(Decimal::fromString((string)$baseline['initial_capital']))!==0)) {
            throw new RuntimeException('PAPER_PORTFOLIO_RESET_REQUIRES_NEW_EPOCH');
        }
        $duplicate=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_paper_nav_snapshots
             WHERE organization_id=:org AND portfolio_id=:portfolio
             AND (snapshot_id=:id OR valued_at=:stamp) LIMIT 1'
        );
        $duplicate->execute(['org'=>$organizationId,'portfolio'=>$portfolioId,'id'=>$id,'stamp'=>$valued]);
        $existing=$duplicate->fetchColumn();
        if (is_string($existing)) {
            $previous=json_decode($existing,true,512,JSON_THROW_ON_ERROR);
            if (is_array($previous) && ($previous['snapshot_id']??null)===$id
                && ($previous['source_fingerprint']??null)===$snapshot['source_fingerprint']
                && ($previous['equity']??null)===$snapshot['equity']) return;
            throw new RuntimeException('CONFLICTING_PAPER_NAV_SNAPSHOT');
        }
        $record=$snapshot;
        $record['currency']=$currency;
        $record['initial_capital']=$initial->value();
        $record['equity']=$equity->value();
        $record['valued_at']=$at->format(DATE_ATOM);
        $stmt=$this->connection->prepare(
            'INSERT INTO tn_capital_market_paper_nav_snapshots
             (organization_id,portfolio_id,snapshot_id,valued_at,currency,initial_capital,source_fingerprint,record_json)
             VALUES (:org,:portfolio,:id,:stamp,:currency,:initial,:fingerprint,:record)'
        );
        $stmt->execute([
            'org'=>$organizationId,'portfolio'=>$portfolioId,'id'=>$id,'stamp'=>$valued,
            'currency'=>$currency,'initial'=>$initial->value(),
            'fingerprint'=>$snapshot['source_fingerprint'],
            'record'=>json_encode($record,JSON_THROW_ON_ERROR),
        ]);
    }

    public function history(string $organizationId,string $portfolioId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if ($organizationId==='' || $portfolioId==='') throw new InvalidArgumentException('Paper NAV requires tenant and portfolio.');
        $stmt=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_paper_nav_snapshots
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
        if (count($rows)>5000) throw new RuntimeException('PAPER_NAV_HISTORY_TRUNCATED');
        $result=[];
        foreach (array_reverse($rows) as $json) {
            $snapshot=json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['mode']??null)!=='PAPER') {
                throw new RuntimeException('INVALID_PAPER_NAV_SNAPSHOT_HISTORY');
            }
            $result[]=$snapshot;
        }
        return $result;
    }
}
