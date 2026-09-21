<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use InvalidArgumentException;

final readonly class Actor
{
    public function __construct(public string $type, public string $id)
    {
        if (trim($this->type) === '' || trim($this->id) === '') {
            throw new InvalidArgumentException('Audit actor requires type and id.');
        }
    }

    public function kind(): ActorKind
    {
        return match (strtolower(trim($this->type))) {
            'user', 'human' => ActorKind::HUMAN,
            'agent' => ActorKind::AGENT,
            default => ActorKind::SYSTEM,
        };
    }

    /** @return array{type:string,id:string,kind:string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'kind' => $this->kind()->value];
    }
}
