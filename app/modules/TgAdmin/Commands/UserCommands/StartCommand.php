<?php
declare(strict_types=1);

namespace Modules\TgAdmin\Commands\UserCommands;

use Common\Services\TelegramAutomationService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Phalcon\Di\Di;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Підключити Terra Nova';
    protected $usage = '/start';
    protected $version = '2.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat = $message->getChat();
        $from = $message->getFrom();
        $service = Di::getDefault()->getShared('telegramAutomationService');
        assert($service instanceof TelegramAutomationService);

        $token = trim((string) $message->getText(true));
        if ($token !== '') {
            $result = $service->consumeLinkToken($token, [
                'telegram_user_id' => (int) $from->getId(),
                'chat_id' => (int) $chat->getId(),
                'username' => (string) $from->getUsername(),
                'first_name' => (string) $from->getFirstName(),
                'last_name' => (string) $from->getLastName(),
            ]);

            $text = $result['ok']
                ? '<b>Terra Nova підключено</b>' . "\n" . htmlspecialchars((string) ($result['user']['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "\nСповіщення активні. Команда /tasks покаже поточний робочий стан."
                : '<b>Не вдалося підключити акаунт</b>' . "\n" . htmlspecialchars((string) $result['message'], ENT_QUOTES, 'UTF-8');
        } else {
            $binding = $service->bindingForTelegram((int) $from->getId());
            $text = $binding
                ? '<b>Telegram уже підключено</b>' . "\n" . htmlspecialchars((string) ($binding['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "\nКоманда /tasks покаже робочий стан."
                : '<b>Terra Nova</b>' . "\nВідкрийте кабінет на сайті та натисніть «Підключити Telegram». Посилання одноразове і діє 15 хвилин.";
        }

        return Request::sendMessage([
            'chat_id' => $chat->getId(),
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }
}
