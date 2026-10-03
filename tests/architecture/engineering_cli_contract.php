<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2).'/symfony/src/Command';
$commands = [
    'EngineeringCreateCommand.php' => 'cos:engineering:create',
    'EngineeringStartCommand.php' => 'cos:engineering:start',
    'EngineeringStatusCommand.php' => 'cos:engineering:status',
];

foreach ($commands as $file => $name) {
    $content = (string) file_get_contents($root.'/'.$file);
    if (!str_contains($content, $name)) throw new RuntimeException('Engineering CLI command missing: '.$name);
}

echo "Engineering CLI contract passed.\n";
