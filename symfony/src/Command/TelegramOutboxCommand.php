<?php
declare(strict_types=1);

namespace App\Command;

use Infrastructure\Integration\Telegram\TelegramAutomationProcessor;
use Infrastructure\Integration\Telegram\TelegramAutomationService;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:telegram:process',
    description: 'Process the durable Telegram notification outbox through the canonical Symfony runtime.',
)]
final class TelegramOutboxCommand extends Command
{
    public function __construct(
        private readonly PdoConnection $database,
        private readonly TelegramAutomationService $automation,
        private readonly string $token,
        private readonly string $publicUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schedule', null, InputOption::VALUE_NONE, 'Queue due reminder notifications before delivery.')
            ->addOption('digest', null, InputOption::VALUE_NONE, 'Queue the daily manager digest before delivery.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum outbox items to process.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduled = $input->getOption('schedule') ? $this->automation->scheduleDueReminders() : 0;
        $digests = $input->getOption('digest') ? $this->automation->queueDailyDigest() : 0;

        if (trim($this->token) === '') {
            $output->writeln(json_encode([
                'disabled' => true,
                'scheduled' => $scheduled,
                'digests' => $digests,
                'message' => 'TELEGRAM_BOT_TOKEN is not configured.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $baseUrl = rtrim(trim($this->publicUrl), '/');
        $token = trim($this->token);
        $processor = new TelegramAutomationProcessor(
            $this->database,
            static function (int $chatId, array $payload) use ($baseUrl, $token): bool|string {
                $data = [
                    'chat_id' => $chatId,
                    'text' => (string) ($payload['text'] ?? ''),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ];

                $rows = [];
                foreach ((array) ($payload['buttons'] ?? []) as $button) {
                    $label = trim((string) ($button[0] ?? ''));
                    $target = trim((string) ($button[1] ?? ''));
                    if ($label === '' || $target === '' || str_ends_with($target, '/0')) {
                        continue;
                    }

                    $url = preg_match('~^https?://~i', $target) === 1
                        ? $target
                        : $baseUrl . '/' . ltrim($target, '/');

                    if ($url !== '') {
                        $rows[] = [['text' => $label, 'url' => $url]];
                    }
                }
                if ($rows !== []) {
                    $data['reply_markup'] = ['inline_keyboard' => $rows];
                }

                $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
                curl_setopt_array($curl, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                ]);

                $body = curl_exec($curl);
                $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_error($curl);
                unset($curl);

                if (!is_string($body) || $body === '') {
                    return $error !== '' ? $error : 'Telegram API returned an empty response.';
                }

                try {
                    $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    return 'Telegram HTTP ' . $httpCode . ': invalid JSON response.';
                }

                if ($httpCode >= 200 && $httpCode < 300 && (bool) ($response['ok'] ?? false)) {
                    return true;
                }

                return 'Telegram HTTP ' . $httpCode . ': ' . mb_substr(
                    (string) ($response['description'] ?? 'sendMessage failed'),
                    0,
                    500,
                );
            },
        );

        $result = $processor->process(max(1, min(100, (int) $input->getOption('limit'))));
        $result['scheduled'] = $scheduled;
        $result['digests'] = $digests;

        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return Command::SUCCESS;
    }
}
