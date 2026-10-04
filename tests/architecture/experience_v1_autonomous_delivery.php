<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'symfony/src/Web/Experience/Delivery/PageDeliveryContextPackage.php',
    'symfony/src/Web/Experience/Delivery/PageDeliveryContextPackageBuilder.php',
    'symfony/src/Web/Experience/Delivery/PageDeliveryWorkflow.php',
    'symfony/src/Web/Experience/Delivery/PageQaFinding.php',
    'symfony/src/Web/Experience/Delivery/PageDeliveryEvidenceContract.php',
    'symfony/src/Web/Experience/Delivery/PageDeliveryEvidenceBuilder.php',
    'symfony/src/Web/Experience/Delivery/PageDeliveryPullRequestTemplate.php',
    'symfony/src/Command/ExperiencePageDeliveryCommand.php',
];

foreach ($files as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-004 autonomous delivery file missing: '.$file);
    }
}

$builder = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryContextPackageBuilder.php');
foreach ([
    'PageContractRegistryInterface',
    'PageArchetypeRegistry',
    'PatternRegistry',
    'human_acceptance',
    'screenshots required',
    'Do not introduce a second frontend runtime',
] as $marker) {
    if (!str_contains($builder, $marker)) {
        throw new RuntimeException('EX-004 Context Package contract missing: '.$marker);
    }
}

$workflow = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryWorkflow.php');
foreach (['EngineeringRequest', 'EngineeringOrchestrator', 'experience_page_delivery', 'experience_evidence_contract', 'experience_pull_request_template'] as $marker) {
    if (!str_contains($workflow, $marker)) {
        throw new RuntimeException('EX-004 must reuse the existing Engineering autonomous cycle: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExperiencePageDeliveryCommand.php');
if (!str_contains($command, "name: 'cos:experience:delivery'") || !str_contains($command, "addOption('start'")) {
    throw new RuntimeException('EX-004 Experience delivery CLI is incomplete.');
}

echo "EX-004 autonomous UI delivery foundation OK\n";
