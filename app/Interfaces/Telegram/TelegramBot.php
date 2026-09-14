<?php


namespace Interfaces\Telegram;

use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Exception\TelegramLogException;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;

class TelegramBot
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
            TelegramLog::error($e);
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
    }

}
