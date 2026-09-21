<?php

declare(strict_types=1);

namespace App\Web\Experience\Native;

use App\Web\Experience\Model\EntityRef;

final readonly class DeepLink
{
    public function __construct(
        public EntityRef $entity,
        public string $webPath,
        public string $nativeUri,
    ) {
    }
}
