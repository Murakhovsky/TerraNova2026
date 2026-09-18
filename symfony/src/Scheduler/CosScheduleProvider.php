<?php
declare(strict_types=1);

namespace App\Scheduler;

use App\Application\Sales\Command\RunSalesAutomationCommand;
use App\Application\Sales\Command\RunSalesAutomationCommand;
use App\Application\System\Command\DrainSalesOutboxCommand;
use App\Application\System\Command\SchedulerHeartbeatCommand;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('cos')]
final class CosScheduleProvider implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())->with(
            RecurringMessage::every(
                '5 minutes',
                new RedispatchMessage(new RunSalesAutomationCommand(), 'async'),
            ),
            RecurringMessage::every(
                '15 minutes',
                new RedispatchMessage(new SchedulerHeartbeatCommand('scheduled'), 'async'),
            ),
            RecurringMessage::every(
                '5 minutes',
                new RedispatchMessage(new RunSalesAutomationCommand(runId: 'scheduler'), 'async'),
            ),
            RecurringMessage::every(
                '1 minute',
                new RedispatchMessage(new DrainSalesOutboxCommand(200, 'scheduler-sales-outbox'), 'async'),
            ),
        );
    }
}
