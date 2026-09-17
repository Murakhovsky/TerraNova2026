<?php
declare(strict_types=1);

namespace Kernel\Application\Bus;

use Kernel\Application\Query\QueryInterface;

interface QueryBusInterface
{
    public function ask(QueryInterface $query): mixed;
}
