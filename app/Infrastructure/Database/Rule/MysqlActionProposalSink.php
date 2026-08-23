<?php
declare(strict_types=1);

namespace Infrastructure\Database\Rule;

use Kernel\Action\ActionProposal;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\ActionProposalSinkInterface;
use Kernel\Action\Service\ActionService;

final readonly class MysqlActionProposalSink implements ActionProposalSinkInterface
{
    public function __construct(private ActionService $actions) {}

    public function accept(DomainEvent $event, ActionProposal $proposal): void
    {
        $this->actions->propose($event->organizationId, $proposal, $event->metadata->correlationId);
    }
}
