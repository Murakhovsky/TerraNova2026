<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach ([
    'resources/experience/external-reference.yaml',
    'symfony/src/Web/Experience/External/ExternalReferenceAuditReport.php',
    'symfony/src/Web/Experience/External/ExternalReferenceAuditService.php',
    'symfony/src/Command/ExternalReferenceAuditCommand.php',
] as $file) {
    if (!is_file($root.'/'.$file)) {
        throw new RuntimeException('EX-006 reference artifact missing: '.$file);
    }
}

$manifest = (string) file_get_contents($root.'/resources/experience/external-reference.yaml');
foreach (['auth.login:', 'cabinet:', 'property.catalog:', 'public.cos.lang:', 'workspace_shell.html.twig'] as $marker) {
    if (!str_contains($manifest, $marker)) {
        throw new RuntimeException('EX-006 external reference contract missing: '.$marker);
    }
}
if (substr_count($manifest, 'template: ') !== 4) {
    throw new RuntimeException('EX-006 must define exactly four external reference templates.');
}

$command = (string) file_get_contents($root.'/symfony/src/Command/ExternalReferenceAuditCommand.php');
if (!str_contains($command, "name: 'cos:experience:external:reference'") || !str_contains($command, "addOption('strict'")) {
    throw new RuntimeException('EX-006 external reference command is incomplete.');
}

echo "EX-006 external reference set contract OK\n";
