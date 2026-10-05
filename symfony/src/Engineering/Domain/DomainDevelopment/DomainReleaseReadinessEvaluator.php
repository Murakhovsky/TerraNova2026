<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

final class DomainReleaseReadinessEvaluator
{
    /** @param array<string,mixed> $domain
     *  @param list<array<string,mixed>> $features
     *  @param list<array<string,mixed>> $contracts
     *  @return array{ready:bool,blockers:list<string>}
     */
    public function evaluate(array $domain, array $features, array $contracts): array
    {
        $blockers = [];
        $architectureVersion = (int) ($domain['architecture_version'] ?? 0);
        if ($architectureVersion < 1) $blockers[] = 'Domain Architecture is not approved.';

        foreach ($features as $feature) {
            if (!(bool) ($feature['required'] ?? true)) continue;
            $key = (string) ($feature['feature_key'] ?? $feature['id'] ?? 'unknown');
            if (($feature['status'] ?? null) !== DomainFeatureStatus::COMPLETED->value) {
                $blockers[] = 'Required feature '.$key.' is not COMPLETED.';
            }
            if ((int) ($feature['architecture_version'] ?? 0) !== $architectureVersion) {
                $blockers[] = 'Required feature '.$key.' was not validated against current Domain Architecture.';
            }
        }

        if (($domain['qa_status'] ?? null) !== 'PASS') $blockers[] = 'Domain QA has not passed.';

        foreach ($contracts as $contract) {
            if (($contract['status'] ?? 'ACTIVE') === 'BROKEN') {
                $blockers[] = 'Contract '.(string) ($contract['contract_key'] ?? 'unknown').' is BROKEN.';
            }
        }

        return ['ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers))];
    }
}
