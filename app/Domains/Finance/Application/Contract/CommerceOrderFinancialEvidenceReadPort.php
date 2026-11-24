<?php
declare(strict_types=1);

namespace Domains\Finance\Application\Contract;

/**
 * Trusted internal read boundary, not a public capability or payment executor.
 * Finance owns confirmed financial facts; Sales owns placed commercial terms.
 * Implementations must scope every lookup to the organization and order.
 */
interface CommerceOrderFinancialEvidenceReadPort
{
    /**
     * Null means no confirmed Finance evidence, never zero payment.
     * Amounts are exact nonnegative decimal strings in the order currency.
     * Captures, refunds and chargebacks include only independently confirmed
     * operations; chargebacks are net of confirmed reversals, and no debit
     * may be counted as both a refund and a chargeback.
     * outstanding_amount is a collection balance, not accounting revenue.
     * No FX, provider status inference, or customer/payment credentials.
     *
     * @return array{schema_version:string,organization_id:string,order_id:string,currency:string,evidence_reference:string,revision:int,captured_amount:string,refunded_amount:string,chargeback_amount:string,net_collected_amount:string,outstanding_amount:string}|null
     */
    public function financialEvidence(string $organizationId, string $orderId): ?array;
}
