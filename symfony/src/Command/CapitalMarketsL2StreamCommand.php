<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
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
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly L2LiveWorkerFactory $factory,
        private readonly L2AlertDelivery $alertDelivery,
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
            if ($input->getOption('dry-run')) {
                $output->writeln(json_encode(['status'=>'VALIDATED','enabled'=>$config->enabled,
                    'venue'=>$config->subscription->venue,'symbol'=>$config->subscription->symbol,
                    'source'=>$required['source'], 'alerts_enabled'=>$alertsEnabled], JSON_THROW_ON_ERROR));
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
            $publish = new L2VerifiedIngestionCallback(new L2IngestionPublisher(
                $this->ingestion, $required['organization'], $sourceId, $descriptor->venueId,
                $config->subscription->symbol,
            ));
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
            $onTransition = static function (string $transition, array $context) use ($alerts, $config): void {
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
                fn(int $epoch) => $this->factory->create($config->subscription, $base, $quote, $config->staleAfterSeconds),
                    $publish, $config->messageBudget, null, $signal->shouldStop(...),
                    $onTransition,
                );
            } finally {
                $alerts?->flush();
            }
            $output->writeln(json_encode(['status'=>'STOPPED','published'=>$count,
                'diagnostics'=>$managed->diagnostics(),
                'alerts'=>['enabled'=>$alertsEnabled,'pending'=>$alerts?->pendingCount() ?? 0],
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
