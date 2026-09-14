<?php
declare(strict_types=1);

namespace Domains\Property\Application\DTO;

use InvalidArgumentException;

final readonly class PropertyCommandContext
{
    public function __construct(
        public string $organizationId,
        public string $correlationId,
        public string $actorType,
        public string $actorId,
    ) {
        foreach ([
            'organizationId' => $this->organizationId,
            'correlationId' => $this->correlationId,
            'actorType' => $this->actorType,
            'actorId' => $this->actorId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Property command ' . $field . ' is required.');
            }
        }
    }
}
