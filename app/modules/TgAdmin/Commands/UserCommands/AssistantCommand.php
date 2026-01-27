<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Dialogs\Dialogs;
use Exception;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use OpenAIPlugin\GPTClient;
use Telegram\Featuring\Messages;
use Telegram\Featuring\TelegramUserException;
use TelegramModels\Adverts\RealtyRentCommercial;
use TelegramModels\TelegramEbAssistant;
use OpenAI\Exceptions\ErrorException;
use TelegramModels\TelegramEbRequest;
use User\Users;

class AssistantCommand extends UserCommand
{
    protected $name = 'ass';

    protected $usage = '/ass';

    protected $version = '1.0';
     private array $model = array("gpt-3.5-turbo", "gpt-4-turbo-preview");
//    private string $assistant_id = "3e23a07a6a4883b68e2698af70acb13d";
    private string $assistant_id = 'asst_qmFf70WDlAbuFKImMMbbjJeJ';
    private string $vector_store_id = 'vs_nl8xalHSQAytWMGjI9chXgdR';

    public function execute(): ServerResponse
    {
        try {
            $message = $this->getMessage();
            $chat_id = $message->getChat()->getId();
            $input_text = trim($message->getText(true));

            $user_id = Users::getIdFromTgUser($message);

            // Отримуємо екземпляр з'єднання з GPT і передаємо ідентифікатор для отриманн даних
            $client_GPT = new GPTClient($user_id);
            $answer = $client_GPT->send_request($input_text, $chat_id);

//            $answer = "Згідно з наявною інформацією, зараз у вашому агентстві налічується {objects_count} об'єктів нерухомості та {requsts_count} запитів на пошук нерухомості. Якщо потрібно більше деталей, дайте знати!";
            $result = $client_GPT->replaceVariable($answer);

            $this->replyToChat($result);

        }catch (TelegramUserException $e) {
            $this->replyToChat($e->getMessage());

        }catch (ErrorException $e) {
            Messages::sendMeMessage($e->getErrorCode() . ": " . $e->getMessage());
        }
        return Request::emptyResponse();
    }




    private function requsts_count(): int|null {
        return TelegramEbRequest::count();
    }
    private function objects_count(): int|null {
        Messages::sendMeMessage('Замінений текст: ');
        return RealtyRentCommercial::count();
    }
}