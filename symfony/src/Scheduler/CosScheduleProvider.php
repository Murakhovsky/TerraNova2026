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
use App\Application\Engineering\Command\ContinueEngineeringWorkflowsCommand;
use App\Application\Engineering\Command\ContinueEngineeringDomainsCommand;
use App\Application\Engineering\Command\WatchEngineeringRuntimeCommand;
use App\Application\CapitalMarkets\Command\CapturePaperNavSnapshot;
use App\Application\CapitalMarkets\Command\RefreshCrossVenueCandidatePool;
use App\Application\CapitalMarkets\Command\SampleBStockBboQuotes;
use App\Application\CapitalMarkets\Command\SweepBStockBboAdmissions;
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
        private readonly bool $engineeringAutonomyEnabled = false,
        private readonly int $engineeringAutonomyIntervalMinutes = 2,
        private readonly bool $engineeringDomainAutonomyEnabled = false,
        private readonly int $engineeringDomainAutonomyIntervalMinutes = 3,
        private readonly bool $engineeringRuntimeWatchdogEnabled = true,
        private readonly int $engineeringRuntimeWatchdogIntervalMinutes = 2,
        private readonly bool $capitalMarketsPaperNavEnabled = false,
        private readonly string $capitalMarketsPaperNavOrganizationId = '',
        private readonly int $capitalMarketsPaperNavIntervalMinutes = 10,
        private readonly bool $capitalMarketsDiscoveryEnabled = false,
        private readonly string $capitalMarketsDiscoveryOrganizationId = '',
        private readonly int $capitalMarketsDiscoveryActorId = 0,
        private readonly int $capitalMarketsDiscoveryIntervalMinutes = 1440,
        private readonly bool $capitalMarketsBboPollEnabled = false,
        private readonly string $capitalMarketsBboPollOrganizationId = '',
        private readonly int $capitalMarketsBboPollActorId = 0,
        private readonly int $capitalMarketsBboPollIntervalMinutes = 15,
        private readonly bool $capitalMarketsBboFenceEnabled = false,
        private readonly string $capitalMarketsBboFenceOrganizationId = '',
        private readonly int $capitalMarketsBboFenceActorId = 0,
        private readonly int $capitalMarketsBboFenceIntervalMinutes = 5,
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
        if($this->engineeringAutonomyIntervalMinutes<1||$this->engineeringAutonomyIntervalMinutes>60){
            throw new InvalidArgumentException('Engineering autonomy interval must be between 1 and 60 minutes.');
        }
        if($this->engineeringDomainAutonomyIntervalMinutes<1||$this->engineeringDomainAutonomyIntervalMinutes>60){
            throw new InvalidArgumentException('Engineering Domain autonomy interval must be between 1 and 60 minutes.');
        }
        if ($this->capitalMarketsPaperNavIntervalMinutes<10||$this->capitalMarketsPaperNavIntervalMinutes>60) {
            throw new InvalidArgumentException('Paper NAV snapshot interval must be 10..60 minutes to preserve 31-day history integrity.');
        }
        if ($this->capitalMarketsPaperNavEnabled && trim($this->capitalMarketsPaperNavOrganizationId)==='') {
            throw new InvalidArgumentException('Paper NAV scheduler requires an existing tenant ID.');
        }
        if ($this->capitalMarketsDiscoveryIntervalMinutes < 15 || $this->capitalMarketsDiscoveryIntervalMinutes > 1440) {
            throw new InvalidArgumentException('Capital Markets Discovery interval must be 15..1440 minutes.');
        }
        if ($this->capitalMarketsBboFenceIntervalMinutes < 1 || $this->capitalMarketsBboFenceIntervalMinutes > 60) {
            throw new InvalidArgumentException('BBO admission fence interval must be 1..60 minutes.');
        }
        if ($this->capitalMarketsBboFenceEnabled
            && (trim($this->capitalMarketsBboFenceOrganizationId)==='' || $this->capitalMarketsBboFenceActorId<1)) {
            throw new InvalidArgumentException('BBO fence requires explicit tenant and service actor.');
        }
        if ($this->capitalMarketsBboPollIntervalMinutes < 5 || $this->capitalMarketsBboPollIntervalMinutes > 1440) {
            throw new InvalidArgumentException('Bounded BBO poll interval must be 5..1440 minutes.');
        }
        if ($this->capitalMarketsBboPollEnabled
            && (trim($this->capitalMarketsBboPollOrganizationId)==='' || $this->capitalMarketsBboPollActorId<1)) {
            throw new InvalidArgumentException('Bounded BBO poll requires explicit tenant and actor.');
        }
        if ($this->capitalMarketsDiscoveryEnabled
            && (trim($this->capitalMarketsDiscoveryOrganizationId) === '' || $this->capitalMarketsDiscoveryActorId < 1)) {
            throw new InvalidArgumentException('Capital Markets Discovery requires explicit tenant and actor.');
        }
        if($this->engineeringRuntimeWatchdogIntervalMinutes<1||$this->engineeringRuntimeWatchdogIntervalMinutes>60){
            throw new InvalidArgumentException('Engineering runtime watchdog interval must be between 1 and 60 minutes.');
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

        if($this->engineeringRuntimeWatchdogEnabled){
            $messages[] = RecurringMessage::every(
                $this->engineeringRuntimeWatchdogIntervalMinutes.' minutes',
                new RedispatchMessage(new WatchEngineeringRuntimeCommand('scheduler'), 'engineering'),
            );
        }

        if($this->engineeringAutonomyEnabled){
            $messages[] = RecurringMessage::every(
                $this->engineeringAutonomyIntervalMinutes.' minutes',
                new RedispatchMessage(new ContinueEngineeringWorkflowsCommand('scheduler'), 'engineering'),
            );
        }

        if($this->engineeringDomainAutonomyEnabled){
            $messages[] = RecurringMessage::every(
                $this->engineeringDomainAutonomyIntervalMinutes.' minutes',
                new RedispatchMessage(new ContinueEngineeringDomainsCommand('scheduler'), 'engineering'),
            );
        }

        if($this->growthAutonomousOutreachEnabled){
            $messages[] = RecurringMessage::every(
                $this->growthAutonomousOutreachIntervalMinutes.' minutes',
                new RedispatchMessage(new RunGrowthAutonomousOutreachCommand('scheduler'), 'async'),
            );
        }

        if ($this->capitalMarketsBboFenceEnabled) {
            $messages[] = RecurringMessage::every(
                $this->capitalMarketsBboFenceIntervalMinutes.' minutes',
                new RedispatchMessage(
                    new SweepBStockBboAdmissions($this->capitalMarketsBboFenceOrganizationId,
                        $this->capitalMarketsBboFenceActorId),
                    'async',
                ),
            );
        }

        if ($this->capitalMarketsBboPollEnabled) {
            $messages[] = RecurringMessage::every(
                $this->capitalMarketsBboPollIntervalMinutes.' minutes',
                new RedispatchMessage(
                    new SampleBStockBboQuotes($this->capitalMarketsBboPollOrganizationId,
                        $this->capitalMarketsBboPollActorId,$this->capitalMarketsBboPollIntervalMinutes*60),
                    'async',
                ),
            );
        }

        if ($this->capitalMarketsDiscoveryEnabled) {
            $messages[] = RecurringMessage::every(
                $this->capitalMarketsDiscoveryIntervalMinutes.' minutes',
                new RedispatchMessage(
                    new RefreshCrossVenueCandidatePool($this->capitalMarketsDiscoveryOrganizationId,$this->capitalMarketsDiscoveryActorId),
                    'async',
                ),
            );
        }

        if ($this->capitalMarketsPaperNavEnabled) {
            $messages[] = RecurringMessage::every(
                $this->capitalMarketsPaperNavIntervalMinutes.' minutes',
                new RedispatchMessage(
                    new CapturePaperNavSnapshot($this->capitalMarketsPaperNavOrganizationId),
                    'async',
                ),
            );
        }

        return $this->schedule = (new Schedule())->with(...$messages);
    }
}
