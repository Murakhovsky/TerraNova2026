<?php
declare(strict_types=1);

namespace App\Infrastructure\Telegram;

use JsonException;

final readonly class TelegramNotificationSender
{
    public function __construct(
        private string $token,
        private string $botName,
        private string $publicUrl,
    ) {
    }

    public function configured(): bool
    {
        return trim($this->token) !== '' && trim($this->botName) !== '';
    }

    /** @param array<string,mixed> $payload */
    public function send(int $chatId, array $payload): bool|string
    {
        if (!$this->configured()) {
            return 'Telegram credentials are not configured.';
        }

        $data = [
            'chat_id' => $chatId,
            'text' => (string) ($payload['text'] ?? ''),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ];

        $rows = [];
        foreach ((array) ($payload['buttons'] ?? []) as $button) {
            if (!is_array($button)) {
                continue;
            }

            $label = trim((string) ($button[0] ?? ''));
            $target = trim((string) ($button[1] ?? ''));
            if ($label === '' || $target === '' || str_ends_with($target, '/0')) {
                continue;
            }

            $url = preg_match('~^https?://~i', $target) === 1
                ? $target
                : rtrim($this->publicUrl, '/') . '/' . ltrim($target, '/');

            $rows[] = [['text' => $label, 'url' => $url]];
        }

        if ($rows !== []) {
            try {
                $data['reply_markup'] = json_encode(
                    ['inline_keyboard' => $rows],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } catch (JsonException $error) {
                return 'Unable to encode Telegram inline keyboard: ' . $error->getMessage();
            }
        }

        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/sendMessage');
        if ($curl === false) {
            return 'Unable to initialize Telegram HTTP transport.';
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($curl);

        if (!is_string($body) || $body === '') {
            return 'Telegram API transport failed: ' . ($transportError !== '' ? $transportError : 'empty response');
        }

        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return 'Telegram API returned invalid JSON: ' . $error->getMessage();
        }

        if ($httpCode >= 200 && $httpCode < 300 && ($response['ok'] ?? false) === true) {
            return true;
        }

        return (string) ($response['description'] ?? ('Telegram API returned HTTP ' . $httpCode));
    }
}
