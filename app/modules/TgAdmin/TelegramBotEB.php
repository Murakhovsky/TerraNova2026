<?php


namespace Modules\TgAdmin;

use AppExceptions\ParserLocalExceptions;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Exception\TelegramLogException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Parser\Advert\CrmSaleHouse;
use Parser\TelegramUpdated;
use Telegram\Featuring\Messages;

class TelegramBotEB
{

    private $config;
    private $DB_config;
    private $bot;

    public function __construct($config, $DB_config, $di){
        try {
            $this->config = $config;
            $this->DB_config = $DB_config;
            // Create Telegram API object

            $this->bot = new Telegram($this->config["api_key"], $this->config["bot_username"]);

            // Enable admin users
            $this->bot->enableAdmins($this->config['admins']->toArray());

            // Add commands paths containing your custom commands
            $this->bot->addCommandsPaths($this->config['commands']['paths']->toArray());


            // Enable MySQL if required
            $mysql_credentials = array(
                'host'     => $this->DB_config["host"],
                'port'     => 3306, // optional
                'user'     => $this->DB_config["username"],
                'password' => $this->DB_config["password"],
                'database' => $this->DB_config["dbname"]
            );

            $this->bot->enableMySql($mysql_credentials, 'telegram_');

            // Logging (Error, Debug and Raw Updates)
            // https://github.com/php-telegram-bot/core/blob/master/doc/01-utils.md#logging
            //
            // (this example requires Monolog: composer require monolog/monolog)
//             Longman\TelegramBot\TelegramLog::initialize(
//                new Monolog\Logger('telegram_bot', [
//                    (new Monolog\Handler\StreamHandler($config['logging']['debug']", Monolog\Logger::DEBUG))->setFormatter(new Monolog\Formatter\LineFormatter(null, null, true)),
//                    (new Monolog\Handler\StreamHandler($config['logging']['error']", Monolog\Logger::ERROR))->setFormatter(new Monolog\Formatter\LineFormatter(null, null, true)),
//                ]),
//                new Monolog\Logger('telegram_bot_updates', [
//                    (new Monolog\Handler\StreamHandler($config['logging']['update']", Monolog\Logger::INFO))->setFormatter(new Monolog\Formatter\LineFormatter('%message%' . PHP_EOL)),
//                ])
//             );

            // Set custom Download and Upload paths
            $this->bot->setDownloadPath($config['paths']['download']);
            $this->bot->setUploadPath($config['paths']['upload']);

            // Load all command-specific configurations
//             foreach ($this->config['commands']['configs'] as $command_name => $command_config) {
//                 $this->telegram->setCommandConfig($command_name, $command_config);
//             }

            // Requests Limiter (tries to prevent reaching Telegram API limits)
            $this->bot->enableLimiter((array)$this->config['limiter']);

            // init language library
            require __DIR__ . "/Language/language_uk.php";
//            $language_array = parse_ini_file(__DIR__ . "/Language/language_uk.ini");
//
//            foreach ($language_array as $key => $value){
//                define($key, $value);
//            }

        }
        catch (TelegramException $e) {
            $result = Request::sendMessage(array(
                'chat_id' => 522625209,
                'text'    => $e->getMessage(),
            ));

            // Log telegram errors
            TelegramLog::error($e);

            // Uncomment this to output any errors (ONLY FOR DEVELOPMENT!)
//            echo $e;
        }
        catch (TelegramLogException $e) {
            // Uncomment this to output log initialisation errors (ONLY FOR DEVELOPMENT!)
//            echo $e;
        }

    }

    public function getBot(){
        return $this->bot;
    }

    public function handle(){
        return $this->bot->handle();

        $inputData = Request::getInput();

        $post = json_decode($inputData, true);
        $oUpdate = new Update($post, 'yuramurahovsky');


        $inputDataArr = $this->messageToArray($inputData);

        $yura_id = 522625209;
        if($inputDataArr["chat_id"] != $yura_id){
//            $this->saveMessage($inputDataArr);
        }else {
//            $this->sendMessage(json_encode($inputDataArr , JSON_UNESCAPED_UNICODE));
//               $this->sendMediaGroup($yura_id, $inputDataArr["text"]);

//            $result = Request::sendMessage(array(
//                'chat_id' => 522625209,
//                'text'    => $inputData,
//            ));

//          Dice
//            if(Request::sendDice(array(
//                'text'    => 'massage',
//                'chat_id' => 522625209
//            ))){
//                $this->sendAction("true");
//            };
        }
    }

    public function sendMediaGroup($yura_id, $advert_id){
        //            photo
        $data = array(
            'chat_id' => $yura_id,
        );
        $realty = CrmSaleHouse::findfirst(array("advert_code = '" . $advert_id . "'","order" => "updated_at DESC"));
        $photos = json_decode($realty->img_links);
        $this->sendMessage(count($photos) . " photos inside.");
        if (count($photos)) {
            $i = 0;
            while ($photos[$i]) {

                $data["media"] = array();
                $j = 0;
                do {
                    $data["media"][] = new InputMediaPhoto(array('media' => $photos[$i++]));
                } while (++$j < 10 && $photos[$i]);

                $test = Request::sendMediaGroup($data);
//                $this->sendMessage($test);
            }
            //$this->sendMessage(json_encode(count($photos)));

        } else {
            $data["text"] = "false";
            $result = Request::sendMessage($data);
        }



    }

    private function sendMessage($massage){
        $chat_id = 522625209;
        $result = Request::sendMessage(array(
            'chat_id' => $chat_id,
            'text'    => $massage,
        ));
    }

    private function customRequest(){
        $token = $this->config["api_key"];
        file_get_contents("https://api.telegram.org/botBOT:" . $token . "/getChat?chat_id=@mychannelname");
    }

    private function messageToArray($message){
        $mess_arr = json_decode($message, JSON_OBJECT_AS_ARRAY);

        $result = array();
        $result["update_id"]        =  $mess_arr["update_id"];
        $result["message_id"]       =  $mess_arr["message"]["message_id"];
        $result["user_id"]          =  $mess_arr["message"]["from"]["user_id"] ;
        $result["is_bot"]           =  $mess_arr["message"]["from"]["is_bot"];
        $result["first_name"]       =  $mess_arr["message"]["from"]["first_name"];
        $result["last_name"]        =  $mess_arr["message"]["from"]["last_name"];
        $result["username"]         =  $mess_arr["message"]["from"]["username"];
        $result["language_code"]    =  $mess_arr["message"]["from"]["language_code"];
        $result["chat_id"]          =  $mess_arr["message"]["chat"]["id"];
        $result["chat_first_name"]  =  $mess_arr["message"]["chat"]["first_name"];
        $result["chat_last_name"]   =  $mess_arr["message"]["chat"]["last_name"];
        $result["chat_username"]    =  $mess_arr["message"]["chat"]["username"];
        $result["type"]             =  $mess_arr["message"]["chat"]["type"];
        $result["date"]             =  date("Y-m-d H:i:s", (int)$mess_arr["message"]["date"] );
        $result["text"]             =  $mess_arr["message"]["text"];

        return $result;
    }

    private function testKeyboardButton(){
        $keyboardButton = new \Longman\TelegramBot\Entities\KeyboardButton("Test text");
        $keyboardButton->setRequestLocation();
    }
}