<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;

/**
 * Normalize raw financial source records without assigning approval or
 * reconciliation authority. The captured fact is never a posted ledger entry.
 */
final class PortfolioNavFinancialEvidencePolicy
{
    private const TYPES=['EXTERNAL_CASH_FLOW','LIABILITY_BALANCE','VENUE_BALANCE','POSITION_BALANCE','ACCOUNT_COVERAGE'];

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function normalize(array $input):array
    {
        $id=trim((string)($input['evidence_id']??''));
        $kind=strtoupper(trim((string)($input['kind']??'')));
        $currency=strtoupper(trim((string)($input['currency']??'')));
        $reference=trim((string)($input['source_reference']??''));
        $provider=trim((string)($input['provider_id']??''));
        $digest=strtolower(trim((string)($input['source_document_sha256']??'')));
        $collector=trim((string)($input['collected_by']??''));
        if ($id===''||strlen($id)>190||$reference===''||strlen($reference)>512
            ||$provider===''||$collector===''||!in_array($kind,self::TYPES,true)
            ||preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,19}$/',$currency)!==1
            ||preg_match('/^[a-f0-9]{64}$/',$digest)!==1) {
            throw new InvalidArgumentException('NAV source evidence requires identity, type, currency, independent source reference and document SHA-256.');
        }
        if (!is_string($input['amount']??null)) {
            throw new InvalidArgumentException('NAV source evidence amount must be an explicit decimal string.');
        }
        $amount=Decimal::fromString($input['amount']);
        if ($kind==='EXTERNAL_CASH_FLOW' && $amount->isZero()) {
            throw new InvalidArgumentException('External cash movement cannot have zero amount.');
        }
        if ($kind!=='EXTERNAL_CASH_FLOW' && $amount->isNegative()) {
            throw new InvalidArgumentException('Balance and liability evidence cannot be negative.');
        }
        $liabilityAccount=trim((string)($input['liability_account_id']??''));
        if ($kind==='LIABILITY_BALANCE' && ($liabilityAccount==='' || strlen($liabilityAccount)>190)) {
            throw new InvalidArgumentException('Liability statements require a stable liability_account_id.');
        }
        $venue=trim((string)($input['venue_id']??''));
        if (in_array($kind,['VENUE_BALANCE','POSITION_BALANCE'],true) && $venue==='') {
            throw new InvalidArgumentException('Venue/position statement requires venue identity.');
        }
        $positionId=trim((string)($input['position_id']??''));
        $instrumentId=trim((string)($input['instrument_id']??''));
        if ($kind==='POSITION_BALANCE' && ($positionId===''||$instrumentId==='')) {
            throw new InvalidArgumentException('Custody position statement requires position and instrument identity.');
        }
        $coverageScope=strtoupper(trim((string)($input['coverage_scope']??'')));
        $coverageFrom=trim((string)($input['coverage_from']??''));
        $coverageThrough=trim((string)($input['coverage_through']??''));
        if ($kind==='ACCOUNT_COVERAGE' && (
            !in_array($coverageScope,['EXTERNAL_FLOWS','LIABILITIES','POSITIONS','VENUES'],true)
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/',$coverageFrom)!==1
            || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/',$coverageThrough)!==1
            || ($input['all_accounts']??null)!==true
            || !$amount->isZero()
        )) {
            throw new InvalidArgumentException('Account coverage needs scope, interval, all-accounts attestation and zero nonfinancial amount.');
        }
        if (!is_string($input['effective_at']??null) || trim($input['effective_at'])==='') {
            throw new InvalidArgumentException('Source effective timestamp is required.');
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/', $input['effective_at']) !== 1) {
            throw new InvalidArgumentException('Effective time must be an offset-aware ISO 8601 timestamp.');
        }
        $utc=new DateTimeZone('UTC');
        $effectiveAt=(new DateTimeImmutable($input['effective_at'],$utc))->setTimezone($utc);
        if ($effectiveAt>new DateTimeImmutable('now',$utc)) {
            throw new InvalidArgumentException('Future-dated NAV source records are not supported.');
        }
        return [
            'evidence_id'=>$id,
            'source_key_sha256'=>hash('sha256',$kind.'|'.$provider.'|'.$reference),
            'kind'=>$kind,
            'currency'=>$currency,
            'amount'=>$amount->value(),
            'venue_id'=>$venue?:null,
            'liability_account_id'=>$kind==='LIABILITY_BALANCE'?$liabilityAccount:null,
            'position_id'=>$kind==='POSITION_BALANCE'?$positionId:null,
            'instrument_id'=>$kind==='POSITION_BALANCE'?$instrumentId:null,
            'coverage_scope'=>$kind==='ACCOUNT_COVERAGE'?$coverageScope:null,
            'coverage_from'=>$kind==='ACCOUNT_COVERAGE'?$coverageFrom:null,
            'coverage_through'=>$kind==='ACCOUNT_COVERAGE'?$coverageThrough:null,
            'all_accounts'=>$kind==='ACCOUNT_COVERAGE' ? true : null,
            'provider_id'=>$provider,
            'source_reference'=>$reference,
            'source_document_sha256'=>$digest,
            'collected_by'=>$collector,
            'effective_at'=>$effectiveAt->format(DATE_ATOM),
            'status'=>'PENDING_RECONCILIATION',
            'reconciled'=>false,
        ];
    }
}
