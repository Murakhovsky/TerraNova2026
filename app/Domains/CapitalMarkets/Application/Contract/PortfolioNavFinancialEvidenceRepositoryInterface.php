<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface PortfolioNavFinancialEvidenceRepositoryInterface
{
    /** @param array<string,mixed> $observation Raw observation, not a reconciled ledger entry. */
    public function append(string $organizationId,string $portfolioId,array $observation):void;

    /** @return list<array<string,mixed>> Returns all bounded source facts; throws if truncated. */
    public function forPortfolio(string $organizationId,string $portfolioId,int $limit=2000):array;
}
