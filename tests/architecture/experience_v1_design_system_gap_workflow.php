<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$registry = (string) file_get_contents($root.'/symfony/src/Web/Experience/Governance/DesignSystemGapRegistry.php');
foreach ([
    "'PENDING'",
    "'APPROVE'",
    "'REUSE_EXISTING'",
    "'PAGE_LOCAL_SPECIALIZATION'",
    "'REJECT'",
    'requires decided_by and evidence',
] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('Design System Gap governance missing: '.$marker);
    }
}

$agents = (string) file_get_contents($root.'/symfony/src/Web/Experience/Delivery/PageDeliveryAgentContract.php');
foreach (['Product UX Architect', 'Design System Guardian', 'design_system_gap_policy'] as $marker) {
    if (!str_contains($agents, $marker)) {
        throw new RuntimeException('Experience agent governance role missing: '.$marker);
    }
}

echo "Experience Design System Gap workflow OK\n";
