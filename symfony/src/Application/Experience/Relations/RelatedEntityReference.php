<?php
declare(strict_types=1);

namespace App\Application\Experience\Relations;

/** Domain verified relationship only, without a guessed presentation URL. */
final readonly class RelatedEntityReference
{
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public string $relation,
        public ?string $detail = null,
    ) {}
}
