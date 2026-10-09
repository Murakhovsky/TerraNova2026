<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\PortfolioLedgerIntegrityAudit;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$valid=[
    'transaction_id'=>'journal-a',
    'posted_at'=>'2026-10-08T11:00:00Z',
    'entries'=>[
        ['account'=>'venue-cash','asset_key'=>'USD','debit'=>'10.25','credit'=>'0'],
        ['account'=>'contra','asset_key'=>'USD','debit'=>'0','credit'=>'10.25'],
        ['account'=>'asset','asset_key'=>'BTC','debit'=>'1','credit'=>'0'],
        ['account'=>'asset-contra','asset_key'=>'BTC','debit'=>'0','credit'=>'1'],
    ],
];
$report=PortfolioLedgerIntegrityAudit::inspect([$valid]);
$assert($report['status']==='JOURNAL_BALANCED','Correct multi-asset journal should balance per asset.');
$assert($report['transactions_checked']===1 && is_string($report['journal_fingerprint']),'Valid journal requires deterministic fingerprint.');
$assert($report['external_flows_reconciled']===false,'Trading ledger integrity must not certify external flows.');
$assert(PortfolioLedgerIntegrityAudit::inspect([$valid])['journal_fingerprint']===$report['journal_fingerprint'],'Journal fingerprint must be deterministic.');
$bad=$valid;$bad['entries'][1]['credit']='10.24';
$assert(in_array('LEDGER_ASSET_IMBALANCE',PortfolioLedgerIntegrityAudit::inspect([$bad])['issues'],true),'Each asset must balance independently.');
$bad=$valid;$bad['entries'][0]['debit']='oops';
$assert(in_array('LEDGER_ENTRY_INVALID',PortfolioLedgerIntegrityAudit::inspect([$bad])['issues'],true),'Invalid decimal must fail closed.');
$bad=$valid;$bad['entries'][0]['credit']='1';
$assert(in_array('LEDGER_ENTRY_INVALID',PortfolioLedgerIntegrityAudit::inspect([$bad])['issues'],true),'Ambiguous dual-sided journal entry must be rejected.');
$bad=$valid;$bad['posted_at']='not-a-date';
$assert(in_array('LEDGER_TIMESTAMP_INVALID',PortfolioLedgerIntegrityAudit::inspect([$bad])['issues'],true),'Missing accounting timestamps must be rejected.');
$assert(in_array('LEDGER_TRANSACTION_IDENTITY_OR_ENTRIES_INVALID',PortfolioLedgerIntegrityAudit::inspect([$valid,$valid])['issues'],true),'Duplicate transaction IDs are not auditable.');
$assert(PortfolioLedgerIntegrityAudit::inspect([])['status']==='INCOMPLETE','Absent trading ledger must not be considered financially reconciled.');
echo "Capital Markets trading ledger integrity audit passed.\n";
