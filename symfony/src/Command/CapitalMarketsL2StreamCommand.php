<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2SourceFencedSession;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2AlertDelivery,L2AlertLifecycle,L2HealthEvaluator,L2IngestionPublisher,L2LiveWorkerFactory,L2ManagedStreamRunner,L2ManagedWorker,L2OperationalMetrics,L2ReconnectPolicy,L2ShutdownSignal,L2StreamSupervisor,L2VerifiedIngestionCallback,L2WorkerRuntimeConfig};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Production entrypoint; intentionally disabled unless explicitly enabled. */
#[AsCommand(name: 'cos:capital-markets:l2:stream', description: 'Run verified L2 market data ingestion with bounded recovery.')]
final class CapitalMarketsL2StreamCommand extends Command
{
    public function __construct(
        private readonly MarketDataIngestionInterface $ingestion,
        private readonly StreamingWorkerLeaseInterface $workerLeases,
        private readonly StreamingTrustInvalidatorInterface $trustInvalidator,
        private readonly StreamingFencedIngestionGateway $fencedGateway,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly L2LiveWorkerFactory $factory,
        private readonly L2AlertDelivery $alertDelivery,
        private readonly VenueRepository $venues,
        private readonly MarketInstrumentResolverInterface $instrumentResolver,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Registered organization id.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Existing enabled verified L2 market source id.')
            ->addOption('venue', null, InputOption::VALUE_REQUIRED, 'BINANCE_SPOT|BINANCE_FUTURES|BYBIT_SPOT|BYBIT_LINEAR|KRAKEN_SPOT.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Exact venue symbol.')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Base asset code.')
            ->addOption('quote', null, InputOption::VALUE_REQUIRED, 'Quote asset code.')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Order book depth.', '50')
            ->addOption('message-budget', null, InputOption::VALUE_REQUIRED, 'Messages per connection.', '1000')
            ->addOption('max-attempts', null, InputOption::VALUE_REQUIRED, 'Maximum reconnect attempts.', '20')
            ->addOption('stale-seconds', null, InputOption::VALUE_REQUIRED, 'Maximum silence after verified publication.', '30')
            ->addOption('min-publication-interval-ms', null, InputOption::VALUE_REQUIRED, 'Optional minimum interval between accepted DB writes.', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate configuration and source without opening sockets.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $required = [];
            foreach (['organization','source','venue','symbol','base','quote'] as $key) {
                $value = trim((string) ($input->getOption($key) ?? ''));
                if ($value === '') throw new DomainException('--'.$key.' is required.');
                $required[$key] = $value;
            }
            $config = L2WorkerRuntimeConfig::fromEnvironment([
                'FOS_L2_ENABLED' => (string) (getenv('FOS_L2_ENABLED') ?: '0'),
                'FOS_L2_VENUE' => $required['venue'],
                'FOS_L2_SYMBOL' => $required['symbol'],
                'FOS_L2_DEPTH' => (string) $input->getOption('depth'),
                'FOS_L2_MESSAGE_BUDGET' => (string) $input->getOption('message-budget'),
                'FOS_L2_MAX_ATTEMPTS' => (string) $input->getOption('max-attempts'),
                'FOS_L2_STALE_SECONDS' => (string) $input->getOption('stale-seconds'),
            ]);
            $alertFlag = getenv('FOS_L2_ALERT_ENABLED');
            $alertFlag = $alertFlag === false ? '0' : $alertFlag;
            if (!in_array($alertFlag, ['0','1'], true)) {
                throw new DomainException('FOS_L2_ALERT_ENABLED must be 0 or 1.');
            }
            $alertsEnabled = $alertFlag === '1';
            $alertEmail = trim((string) (getenv('FOS_L2_ALERT_EMAIL') ?: ''));
            $alertLocale = trim((string) (getenv('FOS_L2_ALERT_LOCALE') ?: 'uk'));
            if ($alertsEnabled && filter_var($alertEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('FOS_L2_ALERT_EMAIL is required when alerts are enabled.');
            }
            if ($alertsEnabled && !in_array($alertLocale, ['uk','en'], true)) {
                throw new DomainException('FOS_L2_ALERT_LOCALE must be uk or en.');
            }
            $base = new AssetCode($required['base']);
            $quote = new AssetCode($required['quote']);
            if ($base->equals($quote)) throw new DomainException('Base and quote assets must differ.');
            $sourceId = MarketSourceId::fromString($required['source']);
            $descriptor = $this->sources->get($required['organization'], $sourceId);
            if ($descriptor === null || !$descriptor->enabled
                || $descriptor->adapterType !== \Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2VerifiedMarketDataDecoder::ADAPTER_TYPE) {
                throw new DomainException('An enabled verified L2 market source must be registered before starting.');
            }
            if ($descriptor->venueId === null) {
                throw new DomainException('Verified L2 market source requires a venue.');
            }
            $venue = $this->venues->get($required['organization'], $descriptor->venueId);
            if ($venue === null || $venue->code !== $config->subscription->venue) {
                throw new DomainException('Source venue does not match the requested L2 venue.');
            }
            $mapped = $this->instrumentResolver->resolve(
                $required['organization'], $descriptor, $config->subscription->symbol,
            );
            if ($mapped === null || $mapped->venueInstrument === null
                || $mapped->instrument->status !== InstrumentStatus::Active
                || $mapped->venueInstrument->status !== VenueInstrumentStatus::Active
                || $mapped->venueInstrument->venueSymbol !== $config->subscription->symbol) {
                throw new DomainException('No active venue/instrument mapping for requested L2 symbol.');
            }
            if ($mapped->instrument->quoteAsset?->value() !== $quote->value()) {
                throw new DomainException('Mapped instrument quote asset does not match L2 configuration.');
            }
            $intervalRaw = (string) $input->getOption('min-publication-interval-ms');
            if (!preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $intervalRaw)
                || (int) $intervalRaw > 60000) {
                throw new DomainException('Invalid minimum L2 publication interval.');
            }
            $minimumPublicationIntervalNs = (int) $intervalRaw * 1000000;
            if ($input->getOption('dry-run')) {
                $output->writeln(json_encode(['status'=>'VALIDATED','enabled'=>$config->enabled,
                    'venue'=>$config->subscription->venue,'symbol'=>$config->subscription->symbol,
                    'source'=>$required['source'], 'instrument'=>$mapped->instrument->id->value(), 'alerts_enabled'=>$alertsEnabled], JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if (!$config->enabled) throw new DomainException('L2 disabled: set FOS_L2_ENABLED=1 explicitly.');
            if (!function_exists('pcntl_signal')) throw new DomainException('pcntl extension required for production shutdown.');
            $signal = new L2ShutdownSignal();
            $signal->install();
            $metrics = new L2OperationalMetrics();
            $policy = new L2ReconnectPolicy(500,30000,$config->maxAttempts);
            $managed = new L2ManagedWorker(new L2ManagedStreamRunner(
                new L2StreamSupervisor($policy), $policy, $metrics,
            ));
            $fenced=getenv('COS_CM_L2_FENCED_ENABLED')==='1';
            $leaseSession=$fenced?new L2SourceFencedSession(
                $this->workerLeases,$this->trustInvalidator,$this->fencedGateway,
                $required['organization'],$required['source'],
            ):null;
            // Only the source-fenced mode is eligible for FOS production pool
            // acceptance; the legacy direct path remains for isolated VPS QA.
            $writer=$leaseSession===null?null:
                static fn(\Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent $raw)=>
                    $leaseSession->publish($raw);
            $publish = new L2VerifiedIngestionCallback(new L2IngestionPublisher(
                $this->ingestion, $required['organization'], $sourceId, $descriptor->venueId,
                $config->subscription->symbol,
                'l2.verified.snapshot',$writer,
            ));
            $lastAcceptedNs = null;
            $verifiedPublisher = $publish;
            $publish = static function (\Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook $book) use (
                $verifiedPublisher, &$lastAcceptedNs, $minimumPublicationIntervalNs,
            ): bool {
                $now = hrtime(true);
                if ($minimumPublicationIntervalNs > 0 && $lastAcceptedNs !== null
                    && $now - $lastAcceptedNs < $minimumPublicationIntervalNs) {
                    return false;
                }
                $verifiedPublisher($book);
                $lastAcceptedNs = $now;
                return true;
            };
            $alerts = $alertsEnabled ? new L2AlertLifecycle(
                $this->alertDelivery, $required['organization'], $alertEmail,
                $required['source'], $config->subscription->venue, $config->subscription->symbol,
                $alertLocale,
                static function (Throwable $error) use ($output): void {
                    $output->writeln('<error>L2 alert enqueue failed: '.htmlspecialchars(
                        $error->getMessage(), ENT_QUOTES,
                    ).'</error>');
                },
            ) : null;
            $onTransition = static function (string $transition, array $context) use ($alerts, $config, $leaseSession): void {
                if(in_array($transition,['DEGRADED','STALE','EXHAUSTED'],true)){
                    $leaseSession?->disconnect('L2_RECOVERY_REQUIRED');
                }
                if ($alerts === null) return;
                $reason = (string) ($context['reason'] ?? 'UNKNOWN');
                if ($transition === 'DEGRADED') {
                    $lastPublication = $context['metrics']['last_publication_at'] ?? null;
                    if ((is_int($lastPublication) && time() - $lastPublication > $config->staleAfterSeconds)
                        || str_contains(strtolower($reason), 'stalled')) {
                        $transition = 'STALE';
                    }
                }
                $alerts->onTransition($transition, $reason);
            };
            try {
                $count = $managed->run(
                function(int $epoch)use($leaseSession,$config,$base,$quote){
                    $leaseSession?->start($epoch);
                    return $this->factory->create(
                        $config->subscription,$base,$quote,$config->staleAfterSeconds
                    );
                },
                    $publish, $config->messageBudget, null, $signal->shouldStop(...),
                    $onTransition,
                );
            } finally {
                $leaseSession?->disconnect('L2_STOPPED');
                $alerts?->flush();
            }
            $output->writeln(json_encode(['status'=>'STOPPED','published'=>$count,
                'diagnostics'=>$managed->diagnostics(),
                'alerts'=>['enabled'=>$alertsEnabled,'pending'=>$alerts?->pendingCount() ?? 0],
                'min_publication_interval_ms'=>(int)$intervalRaw,
                'health'=>(new L2HealthEvaluator($config->staleAfterSeconds))->evaluate(
                    $managed->diagnostics(), $metrics->snapshot(),
                )], JSON_THROW_ON_ERROR));
            return ($alerts?->pendingCount() ?? 0) === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable $error) {
            $output->writeln('<error>L2 streaming failed: '.htmlspecialchars($error->getMessage(), ENT_QUOTES).'</error>');
            return Command::FAILURE;
        }
    }
}
