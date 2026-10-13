<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'resources/experience/personas.yaml',
    'resources/experience/journeys.yaml',
    'resources/experience/terminology.yaml',
    'resources/experience/ux-debt.yaml',
    'resources/experience/design-system/gaps.yaml',
    'symfony/src/Web/Experience/Governance/ProductUxGovernanceReport.php',
    'symfony/src/Web/Experience/Governance/ProductUxGovernanceService.php',
    'symfony/src/Web/Experience/Governance/ProductUxContextProvider.php',
    'symfony/src/Web/Experience/Governance/UxDebtRegistry.php',
    'symfony/src/Web/Experience/Governance/DesignSystemGapRegistry.php',
    'symfony/src/Command/ExperienceUxDebtCommand.php',
    'symfony/src/Command/ExperienceDesignSystemGapsCommand.php',
    'symfony/src/Command/ExperienceProductGovernanceCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('Experience pre-acceptance governance artifact missing: '.$file);
    }
}

$service = (string) file_get_contents($root.'/symfony/src/Web/Experience/Governance/ProductUxGovernanceService.php');
foreach ([
    'missingPersonaReferences',
    'journeyUnknownPersonas',
    'journeyUnknownPages',
    'duplicateTerminologyIds',
    'genericContractCopy',
    'priorityInflated',
    'PageExperienceStatus::Deprecated',
    'PageExperienceStatus::Superseded',
    'PageExperienceStatus::Exempt',
] as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Product UX governance service missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperienceProductGovernanceCommand.php');
foreach (["name: 'cos:experience:product-governance'", "addOption('strict'", 'Structural state'] as $marker) {
    if (!str_contains($command, $marker)) {
        throw new RuntimeException('Product UX governance command missing: '.$marker);
    }
}

$growth = (string) file_get_contents($root.'/resources/experience/pages/growth/golden.yaml');
if (!str_contains($growth, 'status: IMPLEMENTED') || str_contains($growth, 'status: V1_READY')) {
    throw new RuntimeException('Growth Golden page must remain IMPLEMENTED before human acceptance.');
}
foreach (['functional: true', 'visual: true', 'responsive: true', 'accessibility: true', 'human_acceptance: false'] as $marker) {
    if (!str_contains($growth, $marker)) {
        throw new RuntimeException('Growth Golden automated evidence is not synchronized: '.$marker);
    }
}

$release = (string) file_get_contents($root.'/symfony/src/Web/Experience/Release/ExperienceReleaseHardeningService.php');
foreach ([
    'product_ux_structure',
    'product_contract_copy',
    'ux_debt_blockers',
    'design_system_gap_blockers',
    'PageExperienceStatus::Deprecated',
    'PageExperienceStatus::Superseded',
    'PageExperienceStatus::Exempt',
] as $marker) {
    if (!str_contains($release, $marker)) {
        throw new RuntimeException('Experience release pre-acceptance gate missing: '.$marker);
    }
}

$context = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryContextPackage.php');
$builder = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryContextPackageBuilder.php');
$workflow = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryWorkflow.php');
$provider = (string) file_get_contents($root.'/symfony/src/Web/Experience/Governance/ProductUxContextProvider.php');
foreach ([
    'product_ux',
    'ProductUxContextProvider',
    'Use Product UX personas, journeys and canonical terminology',
    'design_system_gap_policy',
] as $marker) {
    if (!str_contains($context.$builder.$workflow.$provider, $marker)) {
        throw new RuntimeException('Autonomous UI delivery is missing Product UX context: '.$marker);
    }
}

echo "Experience V1 pre-acceptance governance OK\n";
