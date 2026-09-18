<?php
declare(strict_types=1);

namespace Kernel\Agent\Service;

use Kernel\Agent\AgentDefinition;

final class AgentOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function create(AgentDefinition $agent): array
    {
        return [
            'type' => 'object',
            'required' => ['decision', 'reason', 'confidence', 'proposed_actions'],
            'properties' => [
                'decision' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'proposed_actions' => [
                    'type' => 'array',
                    'maxItems' => max(0, $agent->maxActionsPerRun),
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'parameters'],
                        'properties' => [
                            'type' => $agent->allowedActionTypes === []
                                ? ['type' => 'string']
                                : ['type' => 'string', 'enum' => $agent->allowedActionTypes],
                            'parameters' => ['type' => 'object'],
                            'target_type' => ['type' => ['string', 'null']],
                            'target_id' => ['type' => ['string', 'null']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'evidence' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => ['string', 'object']],
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
