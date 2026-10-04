<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

use InvalidArgumentException;

final readonly class PageContractId
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value)) {
            throw new InvalidArgumentException('Invalid page contract id: ' . $value);
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
