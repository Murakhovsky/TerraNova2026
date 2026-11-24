<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Persistence;

use DomainException;
use PDO;

/**
 * One read-consistent SQL statement; observes domain-owned projections only.
 * This tool never repairs Stock, posts a movement or changes a reservation.
 */
final readonly class MySqlStockReconciliationAudit
{
    public function __construct(private PDO $connection) {}

    /** @return array<string,mixed> */
    public function audit(string $organizationId, int $limit = 100): array
    {
        if ($organizationId === '' || strlen($organizationId) > 40
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D', $organizationId) !== 1) {
            throw new DomainException('Explicit tenant identity required for stock audit.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Stock audit limit must be 1..500.');
        }

        // Each relevant tenant/variant/location pair is enumerated, including
        // missing projections. All three source sets are fenced by org ID.
        // MySQL's statement snapshot keeps the four figures comparable while
        // native Stock and Reservation transactions proceed independently.
        $sql = <<<'SQL'
WITH stock_pairs AS (
    SELECT variant_id,location_id FROM pm_stock_balances WHERE organization_id=?
    UNION
    SELECT variant_id,location_id FROM pm_stock_movements WHERE organization_id=?
    UNION
    SELECT variant_id,location_id FROM pm_stock_reservations WHERE organization_id=?
),
ledger AS (
    SELECT variant_id,location_id,SUM(quantity_delta) AS ledger_qty
    FROM pm_stock_movements WHERE organization_id=? GROUP BY variant_id,location_id
),
active_reservations AS (
    SELECT variant_id,location_id,SUM(quantity) AS active_qty
    FROM pm_stock_reservations WHERE organization_id=? AND status='active'
    GROUP BY variant_id,location_id
)
SELECT stock_pairs.variant_id,stock_pairs.location_id,
    CAST(COALESCE(b.on_hand,0) AS DECIMAL(20,6)) AS on_hand,
    CAST(COALESCE(b.reserved,0) AS DECIMAL(20,6)) AS reserved,
    CAST(COALESCE(ledger.ledger_qty,0) AS DECIMAL(20,6)) AS ledger_on_hand,
    CAST(COALESCE(active_reservations.active_qty,0) AS DECIMAL(20,6)) AS active_reserved,
    (b.variant_id IS NOT NULL) AS balance_present,
    (COALESCE(b.on_hand,0)=COALESCE(ledger.ledger_qty,0)) AS ledger_matches,
    (COALESCE(b.reserved,0)=COALESCE(active_reservations.active_qty,0)) AS reservation_matches,
    (b.variant_id IS NOT NULL AND b.reserved<=b.on_hand) AS bounds_ok
FROM stock_pairs
LEFT JOIN pm_stock_balances b
    ON b.organization_id=? AND b.variant_id=stock_pairs.variant_id
    AND b.location_id=stock_pairs.location_id
LEFT JOIN ledger ON ledger.variant_id=stock_pairs.variant_id
    AND ledger.location_id=stock_pairs.location_id
LEFT JOIN active_reservations
    ON active_reservations.variant_id=stock_pairs.variant_id
    AND active_reservations.location_id=stock_pairs.location_id
ORDER BY stock_pairs.variant_id,stock_pairs.location_id
LIMIT
SQL;
        // LIMIT is a validated integer. The additional pair makes truncation
        // explicit; never report an incomplete scan as a clean audit.
        $statement = $this->connection->prepare($sql.' '.($limit+1));
        $statement->execute(array_fill(0,6,$organizationId));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $complete = count($rows) <= $limit;
        if (!$complete) $rows = array_slice($rows,0,$limit);

        $mismatches = [];
        foreach ($rows as $row) {
            if ((int)$row['balance_present'] === 1
                && (int)$row['ledger_matches'] === 1
                && (int)$row['reservation_matches'] === 1
                && (int)$row['bounds_ok'] === 1) continue;
            $mismatches[] = [
                'variant_id'=>(string)$row['variant_id'],
                'location_id'=>(string)$row['location_id'],
                'on_hand'=>(string)$row['on_hand'],
                'reserved'=>(string)$row['reserved'],
                'ledger_on_hand'=>(string)$row['ledger_on_hand'],
                'active_reserved'=>(string)$row['active_reserved'],
                'balance_present'=>(int)$row['balance_present']===1,
                'ledger_matches'=>(int)$row['ledger_matches']===1,
                'reservation_matches'=>(int)$row['reservation_matches']===1,
                'bounds_ok'=>(int)$row['bounds_ok']===1,
            ];
        }
        return [
            'status'=>!$complete?'INCOMPLETE':($mismatches!==[]?'DRIFT':'OK'),
            'organization_id'=>$organizationId,
            'checked_pairs'=>count($rows),
            'complete'=>$complete,
            'mismatch_count'=>count($mismatches),
            'mismatches'=>$mismatches,
            'automatic_repair_authorized'=>false,
        ];
    }
}
