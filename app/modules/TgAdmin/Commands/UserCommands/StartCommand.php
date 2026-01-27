<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Modules\TgAdmin\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Renderer\Messages;
use Phalcon\Di\Di;

/**
 * Start command
 */
class StartCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'start';

    /**
     * @var string
     */
    protected $description = 'Start command';

    /**
     * @var string
     */
    protected $usage = '/start';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * Command execute method
     *
     * @return ServerResponse
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $user_name = $message->getFrom()->getUsername();

        $di = Di::getDefault();
        $userService = $di->get('userService');
        $user = $userService->getUser($user_id);


        if($user->status != "public"){
            $user_name = $user->firs_name;
        }

        $first_message_documentation = '<b>ДОКУМЕНТАЦІЯ</b>' . PHP_EOL .
            'https://telegra.ph/Estate-Book-CLUB---Documentation-03-17';

        $first_message_hello = "👋 Вітаємо, $user_name!

🏗 <b>Ти в системі, що поєднує гру, знання та реальну роботу на ринку нерухомості</b>

Цей бот — твоя платформа для:
🔹 професійного навчання у форматі квестів  
🔹 побудови власного рейтингу й кар’єри в ігровій формі  
🔹 управління об’єктами, заявками та угодами  
🔹 заробітку внутрішньої валюти (Coins) за активність  
🔹 участі в дуелях, змаганнях і командних місіях  
🔹 зручної роботи з CRM та документами просто з телефона

---

📊 Твій статус:
👤 Ім’я: $user->first_name 
🔐 Доступ: `Public`  
🎮 Рівень: 0 | Роль: ще не призначена  
💰 Coins: 0 | XP: 0  
📅 Дата реєстрації: -

---

🟢 Наступні кроки:
1️⃣ /profile — Заповнити профіль (перше завдання)  
2️⃣ /quests — Почати проходити квести  
3️⃣ /start_training — Пройти навчання  
4️⃣ /menu — Відкрити головне меню  
5️⃣ /help — Щоб розібратись у можливостях

🎁 Після виконання перших завдань:
+100 Coins | +20 XP | Доступ `Registered` | Роль “Новачок”

---

📌 Цей бот — твоя ігрова оболонка для професійного розвитку, угод, навчання та зростання.

ВСЕ ПОЧИНАЄТЬСЯ ЗАРАЗ!!!.
";


        $first_message_commands = '<b>КОМАНДИ</b>' . PHP_EOL . PHP_EOL .
//            '/start - Основна інформація, команди та посилання' . PHP_EOL . PHP_EOL .
            '/menu - Головне меню сервісу' . PHP_EOL . PHP_EOL .
//            '/submit - створити оголошення' . PHP_EOL .
//            '/favourite - створити список оголошень' . PHP_EOL .
            '/hidekb - аварійне завершення команди у разі системної помилки';

//        Request::sendMessage([
//            'chat_id' => $chat_id,
//            'text' => $first_message_documentation,
//            'parse_mode' => 'html'
//        ]);

        Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => $first_message_hello,
            'parse_mode' => 'html'
        ]);
//        Request::sendMessage([
//            'chat_id' => $chat_id,
//            'text' => $first_message_commands,
//            'parse_mode' => 'html'
//        ]);


    }
}