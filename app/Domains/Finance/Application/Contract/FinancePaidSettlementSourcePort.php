<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/** Composition of the two explicit owner-scoped read ports. */
interface FinancePaidSettlementSourcePort extends MarketingPaidSettlementReadPort, DocumentsPaidSettlementReadPort {}
