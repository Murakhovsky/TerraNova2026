<?php
declare(strict_types=1);

namespace Kernel\Shared\Time;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
