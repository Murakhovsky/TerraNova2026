<?php
declare(strict_types=1);

use Domains\Identity\Application\Contract\TelegramAccountLinkInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Infrastructure\Integration\Telegram\TelegramAutomationService;
use Infrastructure\Integration\Telegram\TelegramAutomationProcessor;

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
require APP_PATH . '/config/loader.php';

$config = require APP_PATH . '/config/config.php';
$database = new PdoConnection($config->database);
$pdo = $database->connection();
$email = 'telegram-test-' . bin2hex(random_bytes(4)) . '@example.test';
$dedupe = 'telegram-integration-' . bin2hex(random_bytes(6));
$telegramUserId = random_int(800000000000, 899999999999);
$chatId = random_int(900000000000, 999999999999);
$userId = 0;

try {
    $statement = $pdo->prepare('INSERT INTO tn_users (email, password_hash, full_name, role, status) VALUES (:email, :hash, "Telegram Test", "admin", "active")');
    $statement->execute(['email' => $email, 'hash' => password_hash('test', PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();

    $service = new TelegramAutomationService($database);
    if (!$service instanceof TelegramAccountLinkInterface) {
        throw new RuntimeException('Telegram account linking is not exposed through the Identity port.');
    }
    $link = $service->createUserLink($userId);
    $bound = $service->consumeLinkToken($link['token'], [
        'telegram_user_id' => $telegramUserId,
        'chat_id' => $chatId,
        'username' => 'tn_test',
        'first_name' => 'Telegram',
        'last_name' => 'Test',
    ]);
    if (!$bound['ok'] || !$service->bindingForUser($userId)) {
        throw new RuntimeException('Account binding failed: ' . json_encode($bound, JSON_UNESCAPED_UNICODE));
    }

    if (!$service->queue('integration.test', 'user', ['text' => 'test'], $userId, null, null, 'user', $userId, $dedupe)) {
        throw new RuntimeException('Outbox insert failed.');
    }

    $sent = [];
    $processor = new TelegramAutomationProcessor($database, static function (int $recipient, array $payload) use (&$sent): bool {
        $sent[] = [$recipient, $payload['text'] ?? null];
        return true;
    });
    $processor->process(10);
    $row = $database->fetchOne('SELECT status, attempts FROM tn_notification_outbox WHERE dedupe_key = :key', ['key' => $dedupe]);
    if (($row['status'] ?? '') !== 'sent' || (int) ($row['attempts'] ?? 0) !== 1 || $sent !== [[$chatId, 'test']]) {
        throw new RuntimeException('Outbox delivery assertion failed.');
    }

    echo "telegram automation integration test passed\n";
} finally {
    $pdo->prepare('DELETE FROM tn_notification_outbox WHERE dedupe_key = :key')->execute(['key' => $dedupe]);
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM tn_users WHERE id = :id')->execute(['id' => $userId]);
    }
}
