<?php
declare(strict_types=1);

/** Cross-track tenant inventory is a read-only operator command, not approval. */
$root=dirname(__DIR__,2);
$src=(string)file_get_contents($root.'/symfony/src/Command/WorkflowAuthorityInventoryCommand.php');
foreach([
    'cos:workflow:authority-inventory',
    '->federation->inspect(',
    '->engineering->engineeringFleet(',
    "'read_only'=>true",
    "'cutover_authorized'=>false",
    "'release_authorized'=>false",
    "'runtime_consumer_fleet_verified'=>false",
    'return Command::FAILURE',
] as $marker) {
    if(!str_contains($src,$marker)) {
        throw new RuntimeException('Execution fleet audit contract incomplete: '.$marker);
    }
}
foreach(['->advance(', '->start(', '->submit(', '->enqueue(', '->promotePristine('] as $effect) {
    if(str_contains($src,$effect)) {
        throw new RuntimeException('Execution fleet audit cannot mutate or dispatch business effects.');
    }
}
echo "COS combined tenant authority audit gate passed; fleet and business approval remain operator-owned.\n";
