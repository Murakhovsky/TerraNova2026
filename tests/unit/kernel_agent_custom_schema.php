<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Service\AgentOutputSchemaFactory;

$custom = [
    'type' => 'object',
    'required' => ['status'],
    'properties' => [
        'status' => ['type' => 'string', 'enum' => ['PASS']],
    ],
    'additionalProperties' => false,
];

$definition = new AgentDefinition(
    name: 'engineering_test',
    version: '0.1',
    systemPrompt: 'test',
    promptVersion: '0.1',
    schemaVersion: '0.1',
    allowedActionTypes: [],
    configurationManaged: false,
    outputSchema: $custom,
);

$actual = (new AgentOutputSchemaFactory())->create($definition);
if ($actual !== $custom) {
    throw new RuntimeException('Kernel AgentDefinition custom output schema was not preserved.');
}

echo "Kernel Agent custom output schema passed.\n";
