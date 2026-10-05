<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'symfony/src/Web/Experience/Golden/GoldenHumanReviewPacketBuilder.php',
    'symfony/src/Command/GoldenHumanReviewCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('Golden human review artifact missing: '.$file);
    }
}

$builder = (string) file_get_contents($root.'/symfony/src/Web/Experience/Golden/GoldenHumanReviewPacketBuilder.php');
foreach ([
    'Only a human product/UX decision',
    'ACCEPT',
    'REQUEST_CHANGES',
    'visual_quality_is_reference_level',
    'EX-005 Workspace Mass Migration remains blocked',
] as $marker) {
    if (!str_contains($builder, $marker)) {
        throw new RuntimeException('Golden human review contract missing: '.$marker);
    }
}

$command = (string) file_get_contents($root.'/symfony/src/Command/GoldenHumanReviewCommand.php');
if (!str_contains($command, "name: 'cos:experience:golden:review'")) {
    throw new RuntimeException('Golden human review command missing.');
}

echo "Golden human review packet contract OK\n";
