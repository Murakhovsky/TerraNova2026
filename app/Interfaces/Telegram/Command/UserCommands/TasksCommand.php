<?php
declare(strict_types=1);

namespace Interfaces\Telegram\Command\UserCommands;

use Infrastructure\Integration\Telegram\TelegramAutomationService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Phalcon\Di\Di;

class TasksCommand extends UserCommand
{
    protected $name = 'tasks';
    protected $description = 'Поточний робочий стан';
    protected $usage = '/tasks';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $service = Di::getDefault()->getShared('telegramAutomationService');
        assert($service instanceof TelegramAutomationService);
        $result = $service->managerSnapshot((int) $message->getFrom()->getId());

        return Request::sendMessage([
            'chat_id' => $message->getChat()->getId(),
            'text' => (string) $result['message'],
            'parse_mode' => 'HTML',
        ]);
    }
}

