<?php
declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:telegram:health',
    description: 'Inspect Telegram Bot API health without exposing the bot token.',
)]
final class TelegramHealthCommand extends Command
{
    public function __construct(private readonly string $token)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = trim($this->token);
        if ($token === '') {
            $output->writeln('<error>TELEGRAM_BOT_TOKEN is not configured.</error>');
            return Command::INVALID;
        }

        $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/getWebhookInfo');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        unset($curl);

        if (!is_string($body) || $body === '') {
            $output->writeln('<error>Telegram API is unavailable: ' . ($error !== '' ? $error : 'empty response') . '</error>');
            return Command::FAILURE;
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $result = (array) ($response['result'] ?? []);

        $output->writeln(json_encode([
            'http_code' => $httpCode,
            'ok' => (bool) ($response['ok'] ?? false),
            'description' => $response['description'] ?? null,
            'url' => $result['url'] ?? null,
            'pending_update_count' => $result['pending_update_count'] ?? null,
            'last_error_date' => $result['last_error_date'] ?? null,
            'last_error_message' => $result['last_error_message'] ?? null,
            'max_connections' => $result['max_connections'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ($httpCode >= 200 && $httpCode < 300 && (bool) ($response['ok'] ?? false))
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
