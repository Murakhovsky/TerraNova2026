<?php
declare(strict_types=1);

namespace Common\Services;

use Modules\TgAdmin\Renderer\Messages;

class LoggerService
{
    private string $logPath;
    private bool $logToStdout;
    private bool $sendToTelegram;
    private string $telegramToken;
    private string $telegramChatId;

    public function __construct(array $config = [])
    {
        $this->logPath = $config['logPath'] ?? APP_PATH . '/common/logs';
        $this->telegramChatId = $config['chat_id'] ?? '';
    }

    public function info(string $label, array $data = []): void
    {
        $this->log('INFO', $label, $data);
    }

    public function debug(string $label, array $data = []): void
    {
        $this->log('DEBUG', $label, $data);
    }

    public function error(string $label, \Throwable|string $error): void
    {
        $data = is_string($error)
            ? ['message' => $error]
            : [
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ];

        $this->log('ERROR', $label, $data);
    }

    private function log(string $level, string $label, array $data = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$level] $label " . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Write to file
        $file = "{$this->logPath}/log_" . date('Y-m-d') . ".log";
        file_put_contents($file, $entry, FILE_APPEND);

        // Output to CLI
        if ($this->logToStdout) {
            echo $entry;
        }

        // Send to Telegram
        if ($level === 'ERROR') {
            Messages::sendMessage($this->telegramChatId,"❗ $label\n" . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }

    private function sendToTelegramMessage(string $text): void
    {
        $url = "https://api.telegram.org/bot{$this->telegramToken}/sendMessage?" . http_build_query([
                'chat_id' => $this->telegramChatId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ]);

        @file_get_contents($url); // Важливо: не зупиняє виконання
    }
}
