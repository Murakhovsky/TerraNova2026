<?php
declare(strict_types=1);

namespace Domains\Property\Application\DTO;

use Domains\Property\Model\PropertyAsset;
use InvalidArgumentException;

final readonly class RegisterPropertyAssetCommand
{
    public function __construct(
        public PropertyCommandContext $context,
        public PropertyAsset $asset,
    ) {
        if ($this->context->organizationId !== $this->asset->organizationId) {
            throw new InvalidArgumentException('PropertyAsset tenant must match command tenant.');
        }
    }
}
