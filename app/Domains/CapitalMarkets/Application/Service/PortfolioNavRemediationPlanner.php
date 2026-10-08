<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

/**
 * Converts NAV preflight issues into an actionable read-only reconciliation
 * checklist. It never approves source documents or creates a valuation.
 */
final class PortfolioNavRemediationPlanner
{
    /** @param array<string,mixed> $preflight @return array<string,mixed> */
    public static function plan(array $preflight): array
    {
        $issues = [];
        foreach ($preflight['issues'] ?? [] as $issue) {
            if (!is_string($issue) || preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $issue) !== 1) {
                $issues['UNRECOGNIZED_PREFLIGHT_ISSUE'] = true;
            } else {
                $issues[$issue] = true;
            }
        }
        if ($issues === []) {
            // Even a clean preflight still requires an independent financial sign-off.
            $issues['NAV_ACCOUNTING_CERTIFICATION_REQUIRED'] = true;
        }
        $workstreams = [
            'SOURCE_DOCUMENTS' => ['order'=>1,'title'=>'Підтвердження первинних документів','owner'=>'Фінансовий контролер',
                'action'=>'Отримати та перевірити незалежні документи про рух коштів, зобов’язання й залишки майданчиків.',
                'evidence'=>'Виписки, первинні документи, SHA-256 та часові мітки'],
            'TRADING_LEDGER' => ['order'=>2,'title'=>'Звірка торгового журналу','owner'=>'Бухгалтер / фінансовий контролер',
                'action'=>'Виправити неузгоджені проводки, дублікати й неповну історію торгового Ledger.',
                'evidence'=>'Збалансовані проводки за кожним активом та незмінний audit trail'],
            'VENUE_CASH' => ['order'=>3,'title'=>'Звірка коштів майданчиків','owner'=>'Оператор інтеграцій',
                'action'=>'Порівняти баланс за кожним майданчиком із його незалежною випискою та перевірити валюту.',
                'evidence'=>'Актуальна незалежна виписка на кожен venue та підтверджені розбіжності'],
            'POSITIONS_MARKS' => ['order'=>4,'title'=>'Позиції та ринкові оцінки','owner'=>'Ризик-менеджер',
                'action'=>'Підтвердити повноту позицій і актуальні котирування; окремо звірити деривативи, заставу та FX.',
                'evidence'=>'Повний набір позицій, BBO/mark provenance, margin та зобов’язання'],
            'PORTFOLIO_SCOPE' => ['order'=>5,'title'=>'Межі портфеля та валюти','owner'=>'Портфельний менеджер',
                'action'=>'Звірити належність усіх позицій і рахунків до портфеля та єдину валюту оцінки.',
                'evidence'=>'Мапа рахунків, портфеля, інструментів і правил конвертації'],
            'CERTIFICATION' => ['order'=>6,'title'=>'Незалежне затвердження NAV','owner'=>'Уповноважений фінансовий контролер',
                'action'=>'Після повної звірки незалежно засвідчити результати та перевірити походження всіх складових NAV.',
                'evidence'=>'Підписане підтвердження ledger, marks, liabilities та зовнішніх cashflows'],
        ];
        $buckets = [];
        foreach (array_keys($issues) as $issue) {
            $bucket = self::classify($issue);
            $buckets[$bucket][] = $issue;
        }
        $tasks = [];
        foreach ($workstreams as $id => $definition) {
            if (!isset($buckets[$id])) continue;
            $codes = $buckets[$id];
            sort($codes, SORT_STRING);
            $tasks[] = [
                'id'=>$id,
                'priority'=>$definition['order'],
                'title'=>$definition['title'],
                'owner_role'=>$definition['owner'],
                'action'=>$definition['action'],
                'required_evidence'=>$definition['evidence'],
                'issue_codes'=>$codes,
                'status'=>'OPEN',
            ];
        }
        $venueRows = $preflight['statement_reconciliation_preview']['venue_comparison'] ?? [];
        $unmatchedVenues = [];
        if (is_array($venueRows)) {
            foreach ($venueRows as $row) {
                if (!is_array($row) || ($row['same_amount'] ?? false) === true) continue;
                $venue = (string)($row['venue_id'] ?? '');
                if ($venue !== '' && strlen($venue) <= 190) $unmatchedVenues[$venue] = true;
            }
        }
        $venues = array_keys($unmatchedVenues);
        sort($venues, SORT_STRING);
        return [
            'status'=>($preflight['status'] ?? '') === 'READY'
                && array_keys($issues) === ['NAV_ACCOUNTING_CERTIFICATION_REQUIRED']
                    ? 'AWAITING_INDEPENDENT_ACCEPTANCE' : 'BLOCKED',
            'tasks'=>$tasks,
            'open_workstreams'=>count($tasks),
            'open_issue_codes'=>count($issues),
            'unmatched_venues'=>$venues,
            'financial_authority'=>'DIAGNOSTIC_ONLY',
            'snapshot_write_allowed'=>false,
        ];
    }

    private static function classify(string $code): string
    {
        if (str_contains($code, 'SOURCE_') || str_contains($code, 'EXTERNAL_FLOW')
            || str_contains($code, 'LIABILITY_') || str_contains($code, 'DOCUMENT_')) {
            return 'SOURCE_DOCUMENTS';
        }
        if (str_contains($code, 'LEDGER_') || str_contains($code, 'JOURNAL_')
            || str_contains($code, 'TRANSACTION_')) return 'TRADING_LEDGER';
        if (str_contains($code, 'VENUE_') || str_contains($code, 'BALANCE_')
            || str_contains($code, 'CASH_') || str_contains($code, 'STATEMENT_')) return 'VENUE_CASH';
        if (str_contains($code, 'MARK_') || str_contains($code, 'POSITION_')
            || str_contains($code, 'DERIVATIVE_') || str_contains($code, 'SHORT_')
            || str_contains($code, 'MARGIN_')) return 'POSITIONS_MARKS';
        if (str_contains($code, 'PORTFOLIO_') || str_contains($code, 'FX_')
            || str_contains($code, 'NONCASH_') || str_contains($code, 'CURRENCY_')) return 'PORTFOLIO_SCOPE';
        return 'CERTIFICATION';
    }
}
