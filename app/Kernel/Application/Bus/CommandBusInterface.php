<?php
declare(strict_types=1);

namespace Kernel\Application\Bus;

use Kernel\Application\Command\CommandInterface;

interface CommandBusInterface
{
    public function dispatch(CommandInterface $command): mixed;
}
