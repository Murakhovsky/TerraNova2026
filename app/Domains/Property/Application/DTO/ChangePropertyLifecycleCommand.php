<?php
declare(strict_types=1);

namespace Domains\Property\Application\DTO;

use Domains\Property\Model\PropertyLifecycle;
use InvalidArgumentException;

final readonly class ChangePropertyLifecycleCommand
{
    public function __construct(
        public PropertyCommandContext $context,
        public string $assetId,
        public PropertyLifecycle $lifecycle,
    ) {
        self::assertAssetId($this->assetId);
    }

    private static function assertAssetId(string $assetId): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $assetId)) {
            throw new InvalidArgumentException('Property command asset id is invalid.');
        }
    }
}
