<?php
declare(strict_types=1);

namespace App\Infrastructure\Telegram;

use Infrastructure\Integration\Telegram\TelegramAutomationProcessor;
use Infrastructure\Integration\Telegram\TelegramAutomationService;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use RuntimeException;

final readonly class TelegramNotificationWorker
{
    public function __construct(
        private PdoConnection $database,
        private TelegramAutomationService $automation,
        private TelegramNotificationSender $sender,
    ) {
    }

    /** @return array{claimed:int,sent:int,failed:int,skipped:int,scheduled:int,digests:int} */
    public function run(bool $schedule = false, bool $digest = false, int $limit = 25): array
    {
        if (!$this->sender->configured()) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_NAME must be configured.');
        }

        $scheduled = $schedule ? $this->automation->scheduleDueReminders() : 0;
        $digests = $digest ? $this->automation->queueDailyDigest() : 0;

        $processor = new TelegramAutomationProcessor(
            $this->database,
            fn (int $chatId, array $payload, array $item): bool|string => $this->sender->send($chatId, $payload),
        );

        $result = $processor->process(max(1, min(100, $limit)));

        return [
            'claimed' => (int) ($result['claimed'] ?? 0),
            'sent' => (int) ($result['sent'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'scheduled' => $scheduled,
            'digests' => $digests,
        ];
    }
}
