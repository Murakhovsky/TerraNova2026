<?php
declare(strict_types=1);

namespace Kernel\Rule\Contract;

use Kernel\Action\ActionProposal;
use Kernel\Event\DomainEvent;

interface ActionProposalSinkInterface
{
    public function accept(DomainEvent $event, ActionProposal $proposal): void;
}
