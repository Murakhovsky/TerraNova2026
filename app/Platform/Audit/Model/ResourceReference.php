<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

use InvalidArgumentException;

final readonly class ResourceReference
{
    public function __construct(public string $type, public string $id)
    {
        if (trim($this->type) === '' || trim($this->id) === '') {
            throw new InvalidArgumentException('Audit resource requires type and id.');
        }
    }

    /** @return array{type:string,id:string} */
    public function toArray(): array { return ['type' => $this->type, 'id' => $this->id]; }
}
