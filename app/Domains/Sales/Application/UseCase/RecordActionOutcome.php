<?php
declare(strict_types=1);
namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\SalesOutcomeRepositoryInterface;
use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Domains\Sales\Automation\Event\ActionOutcomeMeasured;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RecordActionOutcome
{
    public function __construct(private SalesOutcomeRepositoryInterface $outcomes, private EventBus $events, private TransactionManagerInterface $transactions) {}
    public function execute(RecordActionOutcomeCommand $command): string
    {
        return $this->transactions->transactional(function () use ($command): string {
            $id=$this->outcomes->record($command);
            $this->events->publish(ActionOutcomeMeasured::create(bin2hex(random_bytes(16)),$id,$command));
            return $id;
        });
    }
}
