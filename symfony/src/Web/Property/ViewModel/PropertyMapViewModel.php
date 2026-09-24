<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PropertyMapViewModel
{
    /**
     * @param list<array{
     *   id:int,
     *   publicId:string,
     *   title:string,
     *   city:string,
     *   address:string,
     *   coordinates:string,
     *   price:string,
     *   href:string,
     *   x:float,
     *   y:float
     * }> $points
     */
    public function __construct(
        public array $points,
        public int $total,
        public int $mapped,
        public int $unmapped,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->mapped === 0 ? 'empty' : 'normal';
    }
}
