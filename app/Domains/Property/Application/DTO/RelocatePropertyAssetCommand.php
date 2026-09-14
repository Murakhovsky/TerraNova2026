<?php
declare(strict_types=1);

namespace Domains\Property\Application\DTO;

use Domains\Property\Model\PropertyLocation;
use InvalidArgumentException;

final readonly class RelocatePropertyAssetCommand
{
    public function __construct(
        public PropertyCommandContext $context,
        public string $assetId,
        public PropertyLocation $location,
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->assetId)) {
            throw new InvalidArgumentException('Property command asset id is invalid.');
        }
    }
}
