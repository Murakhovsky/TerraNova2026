<?php

declare(strict_types=1);

namespace App\Web\CapitalMarkets;

/**
 * Enforces read-side least privilege for shared Decision Workspace projections.
 *
 * Route authorization alone is insufficient: every Capital Markets view embeds
 * a shared global status, and the Overview route only requires generic View.
 * Redact confidential data BEFORE it reaches Twig or the HTTP response.
 */
final class DecisionWorkspaceVisibilityPolicy
{
    /** @param array<string,mixed> $page @param array<string,bool> $permissions @return array<string,mixed> */
    public static function redact(array $page, array $permissions): array
    {
        $portfolio = ($permissions['portfolio_view'] ?? false) === true;
        $risk = ($permissions['risk_view'] ?? false) === true;
        $opportunity = ($permissions['opportunity_view'] ?? false) === true && $portfolio;
        $research = ($permissions['research_view'] ?? false) === true;
        $allocation = ($permissions['allocation_view'] ?? false) === true;
        $marketQuality = ($permissions['market_data_quality_view'] ?? false) === true;

        $global = is_array($page['global'] ?? null) ? $page['global'] : [];
        if (!$portfolio) {
            foreach ([
                'portfolio_equity', 'available_capital', 'deployed_capital',
                'reserved_capital', 'net_pnl', 'today_net_pnl', 'pnl_30d',
                'paper_equity','paper_nav_status','paper_today_net_pnl','paper_30d_net_pnl',
                'portfolio_updated_at',
                'portfolio_nav_status',
            ] as $key) {
                $global[$key] = null;
            }
            $global['portfolio_nav_windows'] = [];
            $page['paper_nav'] = [];
            $global['paper_nav_windows'] = [];
            $global['last_updated'] = $global['market_updated_at'] ?? null;
            foreach (['capital','performance','exposure','portfolio','capital_map','strategy_allocations'] as $key) {
                if (array_key_exists($key, $page)) {
                    $page[$key] = [];
                }
            }
        }
        if (!$portfolio && is_array($page['venue_rows'] ?? null)) {
            $page['venue_rows'] = array_map(static function (mixed $venue): mixed {
                if (!is_array($venue)) return $venue;
                $venue['capital_locations'] = [];
                $venue['exposure'] = null;
                return $venue;
            }, $page['venue_rows']);
        }
        if (!$portfolio && is_array($page['strategies'] ?? null)) {
            $page['strategies'] = array_map(static function (mixed $strategy): mixed {
                if (!is_array($strategy)) return $strategy;
                $strategy['capital'] = null;
                $strategy['net_pnl'] = null;
                return $strategy;
            }, $page['strategies']);
        }
        if (!$portfolio && array_key_exists('decision_trace', $page)) {
            // Hypothesis traces may include real execution IDs and realized P&L.
            // ResearchView alone cannot authorize those operational details.
            $page['decision_trace'] = [];
        }
        if (!$risk) {
            $global['risk_state'] = 'RESTRICTED';
            $page['risk'] = [];
            $page['material_risks'] = [];
        }
        if (!$opportunity) {
            foreach (['top_opportunities'] as $key) {
                if (array_key_exists($key, $page)) {
                    $page[$key] = [];
                }
            }
        }
        if ((!$research || !$portfolio) && array_key_exists('active_strategies', $page)) {
            $page['active_strategies'] = [];
        }
        // OpportunityView alone permits the signal but not portfolio allocation
        // sizing, portfolio impact or internal allocation decisions.
        if (!$portfolio && is_array($page['opportunities'] ?? null)) {
            $page['opportunities'] = array_map(static function (mixed $row): mixed {
                if (!is_array($row)) return $row;
                $row['approved_capital'] = null;
                $row['portfolio_impact'] = 'RESTRICTED';
                $row['decision'] = 'RESTRICTED';
                $row['priority'] = null;
                $row['reason'] = '';
                return $row;
            }, $page['opportunities']);
        }
        if (!$marketQuality && array_key_exists('quality_rows', $page)) {
            $page['quality_rows'] = [];
        }

        $alerts = is_array($global['alerts'] ?? null) ? $global['alerts'] : [];
        $alerts = array_values(array_filter($alerts, static function (mixed $alert) use ($portfolio, $risk, $marketQuality): bool {
            if (!is_array($alert)) return false;
            $code = (string)($alert['code'] ?? '');
            if (!$risk && $code === 'RISK_STATE') return false;
            if (!$portfolio && $code === 'UNKNOWN_EXPOSURE') return false;
            if (!$marketQuality && $code === 'DATA_HEALTH') return false;
            return true;
        }));
        $global['alerts'] = $alerts;
        $global['critical_alerts'] = count(array_filter(
            $alerts,
            static fn(array $alert): bool => in_array($alert['severity'] ?? null, ['HIGH','CRITICAL'], true),
        ));
        $page['global'] = $global;

        if (is_array($page['recommended_actions'] ?? null)) {
            $page['recommended_actions'] = array_values(array_filter(
                $page['recommended_actions'],
                static function (mixed $action) use ($portfolio, $opportunity, $allocation, $risk, $marketQuality): bool {
                    if (!is_array($action)) return false;
                    $href = (string)($action['href'] ?? '');
                    if ($href === '/capital-markets/performance' && !$portfolio) return false;
                    if (str_starts_with($href, '/capital-markets/opportunities') && !$opportunity) return false;
                    if (str_starts_with($href, '/capital-markets/allocation') && !$allocation) return false;
                    if (str_starts_with($href, '/capital-markets/risk') && !$risk) return false;
                    if (str_starts_with($href, '/capital-markets/data-quality') && !$marketQuality) return false;
                    return true;
                },
            ));
        }
        return $page;
    }
}
