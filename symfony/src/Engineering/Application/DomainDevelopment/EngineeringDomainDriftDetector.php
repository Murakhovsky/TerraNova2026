<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringContractCompatibility;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainArtifactType;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainFeatureStatus;

final readonly class EngineeringDomainDriftDetector
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainContextBuilder $context,
    ) {}

    /** @return list<array{feature_key:string,reason:string}> */
    public function refresh(string $domainId): array
    {
        $architecture = $this->domains->latestArtifact($domainId, EngineeringDomainArtifactType::DOMAIN_ARCHITECTURE->value);
        $architectureVersion = (int) ($architecture['version'] ?? 0);
        $currentContracts = [];
        $breakingContracts = [];
        foreach ($this->domains->contracts($domainId) as $contract) {
            $key = (string) $contract['contract_key'];
            $currentContracts[$key] = (string) $contract['version'];
            if (($contract['compatibility'] ?? null) === EngineeringContractCompatibility::BREAKING->value) $breakingContracts[$key] = true;
        }

        $changed = [];
        foreach ($this->domains->features($domainId) as $feature) {
            if (($feature['engineering_feature_id'] ?? null) === null) continue;
            if (in_array($feature['status'], [EngineeringDomainFeatureStatus::FAILED->value, EngineeringDomainFeatureStatus::CANCELLED->value], true)) continue;

            $reasons = [];
            if ((int) ($feature['architecture_version'] ?? 0) !== $architectureVersion) {
                $reasons[] = 'Domain Architecture version changed.';
            }

            $snapshot = is_array($feature['contract_snapshot'] ?? null) ? $feature['contract_snapshot'] : [];
            foreach ($snapshot as $key => $version) {
                if (($currentContracts[$key] ?? null) !== $version) {
                    $reasons[] = 'Contract '.$key.' changed from '.$version.' to '.($currentContracts[$key] ?? 'missing').'.';
                }
                if (isset($breakingContracts[$key])) $reasons[] = 'Contract '.$key.' is marked BREAKING.';
            }

            if ($reasons === []) continue;
            $status = $feature['status'] === EngineeringDomainFeatureStatus::COMPLETED->value
                ? EngineeringDomainFeatureStatus::REVALIDATION_REQUIRED->value
                : EngineeringDomainFeatureStatus::STALE->value;
            $reason = implode(' ', array_values(array_unique($reasons)));
            $this->domains->updateFeatureStatus($domainId, (string) $feature['feature_key'], $status, $reason);
            $changed[] = ['feature_key' => (string) $feature['feature_key'], 'reason' => $reason];
        }

        return $changed;
    }
}
