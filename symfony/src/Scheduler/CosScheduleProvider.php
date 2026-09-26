<?php
declare(strict_types=1);

namespace App\Scheduler;

use App\Application\Integration\Command\SweepCrmInboxCommand;
use App\Application\Sales\Command\RunSalesAutomationCommand;
use App\Application\Growth\Command\RunGrowthSignalPollingCommand;
use App\Application\Growth\Command\RunGrowthAutonomousOutreachCommand;
use App\Application\Growth\Command\RunGrowthAutonomousContentCommand;
use App\Application\Growth\Command\RunGrowthOutreachSequencesCommand;
use App\Application\Growth\Command\RunGrowthMarketDiscoveryCommand;
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
        private readonly bool $growthAutonomousOutreachEnabled = false,
        private readonly int $growthAutonomousOutreachIntervalMinutes = 5,
        private readonly int $growthAutonomousOutreachActorId = 0,
        private readonly bool $growthAutonomousContentEnabled = false,
        private readonly int $growthAutonomousContentIntervalMinutes = 5,
        private readonly int $growthAutonomousContentActorId = 0,
        private readonly bool $growthOutreachSequenceEnabled = false,
        private readonly int $growthOutreachSequenceIntervalMinutes = 15,
        private readonly int $growthOutreachSequenceActorId = 0,
        private readonly bool $growthMarketDiscoveryEnabled = false,
        private readonly int $growthMarketDiscoveryIntervalMinutes = 60,
        private readonly int $growthMarketDiscoveryActorId = 0,
    ) {
        if($this->growthCollectorPollingIntervalMinutes<1||$this->growthCollectorPollingIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth collector polling interval must be between 1 and 1440 minutes.');
        }
        if($this->growthCollectorPollingEnabled&&$this->growthCollectorPollingActorId<1){
            throw new InvalidArgumentException('Growth collector polling requires a positive system actor id.');
        }
        if($this->growthAutonomousOutreachIntervalMinutes<1||$this->growthAutonomousOutreachIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth autonomous outreach interval must be between 1 and 1440 minutes.');
        }
        if($this->growthAutonomousOutreachEnabled&&$this->growthAutonomousOutreachActorId<1){
            throw new InvalidArgumentException('Growth autonomous outreach requires a positive system actor id.');
        }
        if($this->growthAutonomousContentIntervalMinutes<1||$this->growthAutonomousContentIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth autonomous content interval must be between 1 and 1440 minutes.');
        }
        if($this->growthAutonomousContentEnabled&&$this->growthAutonomousContentActorId<1){
            throw new InvalidArgumentException('Growth autonomous content requires a positive system actor id.');
        }
        if($this->growthOutreachSequenceIntervalMinutes<1||$this->growthOutreachSequenceIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth outreach sequence interval must be between 1 and 1440 minutes.');
        }
        if($this->growthOutreachSequenceEnabled&&$this->growthOutreachSequenceActorId<1){
            throw new InvalidArgumentException('Growth outreach sequences require a positive system actor id.');
        }
        if($this->growthMarketDiscoveryIntervalMinutes<1||$this->growthMarketDiscoveryIntervalMinutes>1440){
            throw new InvalidArgumentException('Growth market discovery interval must be between 1 and 1440 minutes.');
        }
        if($this->growthMarketDiscoveryEnabled&&$this->growthMarketDiscoveryActorId<1){
            throw new InvalidArgumentException('Growth market discovery requires a positive system actor id.');
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

        if($this->growthOutreachSequenceEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthOutreachSequenceIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthOutreachSequencesCommand('scheduler'), 'async'),
            );
        }

        if($this->growthMarketDiscoveryEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthMarketDiscoveryIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthMarketDiscoveryCommand('scheduler'), 'async'),
            );
        }

        if($this->growthAutonomousContentEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthAutonomousContentIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthAutonomousContentCommand('scheduler'), 'async'),
            );
        }

        if($this->growthAutonomousOutreachEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthAutonomousOutreachIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthAutonomousOutreachCommand('scheduler'), 'async'),
            );
        }

        return $this->schedule = (new Schedule())->with(...$messages);
    }
}
