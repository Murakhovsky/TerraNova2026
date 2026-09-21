<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

enum ActivitySource: string
{
    case HUMAN = 'HUMAN';
    case AGENT = 'AGENT';
    case TOOL = 'TOOL';
    case WORKFLOW = 'WORKFLOW';
    case INTEGRATION = 'INTEGRATION';
    case WORKER = 'WORKER';
    case SYSTEM = 'SYSTEM';

    public static function fromActorType(string $actorType): self
    {
        return match (strtolower(trim($actorType))) {
            'user', 'human' => self::HUMAN,
            'agent' => self::AGENT,
            'integration' => self::INTEGRATION,
            'worker' => self::WORKER,
            default => self::SYSTEM,
        };
    }
}
