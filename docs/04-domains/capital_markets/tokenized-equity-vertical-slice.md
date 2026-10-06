# Tokenized Equity Vertical Slice V0.4

This package introduces the deterministic financial core for H1 Tokenized Equity Dislocation and H2 Cross-Venue Tokenized Equity Arbitrage.

## Flow

MarketState -> SpreadCandidate -> Net Economics -> Opportunity/Risk -> Paper Fill -> Ledger/Position/P&L -> Performance.

The implementation reuses Foundation and Market Intelligence V0.3. It cannot submit live orders.

## Invariants

- financial arithmetic uses Decimal, never float;
- BUY uses ask and SELL uses bid;
- larger quantities use order-book VWAP;
- stale/untrusted/closed markets do not produce candidates;
- snapshot age and skew are bounded;
- gross spread is not treated as profit;
- explicit costs are deducted before expected net P&L;
- ledger transactions must balance;
- paper P&L comes from fills, fees and slippage;
- live execution remains out of scope.

## Next integration pack

Persistence/application orchestration persists candidates, opportunities, risk assessments, executions, ledger and positions, then exposes API/UI and connects detection to trusted MarketState updates.
