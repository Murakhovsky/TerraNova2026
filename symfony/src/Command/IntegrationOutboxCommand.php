<?php
declare(strict_types=1);

namespace App\Command;

use Domains\Content\Application\Service\ContentService;
use Infrastructure\Integration\N8n\IntegrationOutboxProcessor;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:integration:n8n:process',
    description: 'Process the durable n8n integration outbox through the canonical Symfony runtime.',
)]
final class IntegrationOutboxCommand extends Command
{
    public function __construct(
        private readonly PdoConnection $database,
        private readonly ContentService $content,
        private readonly string $url,
        private readonly string $secret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schedule-content', null, InputOption::VALUE_NONE, 'Publish due scheduled content before processing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum outbox items to process.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduled = $input->getOption('schedule-content')
            ? $this->content->publishScheduled()
            : 0;

        if (trim($this->url) === '' || $this->secret === '') {
            $output->writeln(json_encode([
                'disabled' => true,
                'scheduled_content' => $scheduled,
                'message' => 'N8N_OUTBOUND_URL or N8N_OUTBOUND_SECRET is not configured.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $url = trim($this->url);
        $secret = $this->secret;
        $processor = new IntegrationOutboxProcessor(
            $this->database,
            static function (string $body, array $item) use ($url, $secret): bool|string {
                $timestamp = (string) time();
                $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
                $curl = curl_init($url);
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'X-TN-Timestamp: ' . $timestamp,
                        'X-TN-Signature: sha256=' . $signature,
                        'X-TN-Idempotency-Key: tn-outbox-' . $item['id'],
                    ],
                ]);
                $response = curl_exec($curl);
                $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_error($curl);
                unset($curl);

                if (!is_string($response)) {
                    return $error !== '' ? $error : 'n8n connection failed.';
                }

                return $httpCode >= 200 && $httpCode < 300
                    ? true
                    : 'n8n HTTP ' . $httpCode . ': ' . mb_substr(strip_tags($response), 0, 500);
            },
        );

        $result = $processor->process(max(1, min(100, (int) $input->getOption('limit'))));
        $result['scheduled_content'] = $scheduled;
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
