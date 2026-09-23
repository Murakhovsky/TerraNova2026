<?php
declare(strict_types=1);

namespace App\Scheduler;

use App\Application\Integration\Command\SweepCrmInboxCommand;
use App\Application\Sales\Command\RunSalesAutomationCommand;
use App\Application\Growth\Command\RunGrowthSignalPollingCommand;
use InvalidArgumentException;
use App\Application\System\Command\DrainSalesOutboxCommand;
use App\Application\System\Command\SchedulerHeartbeatCommand;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('cos')]
final class CosScheduleProvider implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly bool $salesAutomationEnabled = true,
        private readonly bool $growthCollectorPollingEnabled = false,
        private readonly int $growthCollectorPollingIntervalMinutes = 15,
        private readonly int $growthCollectorPollingActorId = 0,
    ) {
        if($this->growthCollectorPollingIntervalMinutes<1||$this->growthCollectorPollingIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth collector polling interval must be between 1 and 1440 minutes.');
        }
        if($this->growthCollectorPollingEnabled&&$this->growthCollectorPollingActorId<1){
            throw new InvalidArgumentException('Growth collector polling requires a positive system actor id.');
        }
    }

    public function getSchedule(): Schedule
    {
        if ($this->schedule instanceof Schedule) {
            return $this->schedule;
        }

        $messages = [
            RecurringMessage::every(
                '15 minutes',
                new RedispatchMessage(new SchedulerHeartbeatCommand('scheduled'), 'async'),
            ),
            RecurringMessage::every(
                '1 minute',
                new RedispatchMessage(new SweepCrmInboxCommand(200), 'async'),
            ),
        ];

        if ($this->salesAutomationEnabled) {
            $messages[] = RecurringMessage::every(
                '5 minutes',
                new RedispatchMessage(new RunSalesAutomationCommand(runId: 'scheduler'), 'async'),
            );
            $messages[] = RecurringMessage::every(
                '1 minute',
                new RedispatchMessage(new DrainSalesOutboxCommand(200, 'scheduler-sales-outbox'), 'async'),
            );
        }

        if($this->growthCollectorPollingEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthCollectorPollingIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthSignalPollingCommand('scheduler'), 'async'),
            );
        }

        return $this->schedule = (new Schedule())->with(...$messages);
    }
}
