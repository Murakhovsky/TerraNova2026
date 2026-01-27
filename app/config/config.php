<?php
/*
 * Modified: prepend directory path of current file, because of this file own different ENV under between Apache and command line.
 * NOTE: please remove this comment.
 */
defined('BASE_PATH') || define('BASE_PATH', getenv('BASE_PATH') ?: realpath(dirname(__FILE__) . '/../..'));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');
defined('DOMAIN_NAME') || define('DOMAIN_NAME', 'https://' . $_SERVER["HTTP_HOST"]);

return new \Phalcon\Config\Config([
    'version' => '1.0',

    'database' => [
        'adapter'  => 'Mysql',
        'host'     => 'itsige00.mysql.tools', // getenv('DB_HOST'),
        'username' => 'itsige00_assistant', // getenv('DB_USER'),
        'password' => '6t5m#%8KLd', //getenv('DB_PASS'),
        'dbname'   => 'itsige00_assistant', //getenv('DB_NAME'),
        'charset'  => 'utf8',
    ],

    'application' => [
        'appDir'         => APP_PATH . '/',
        'modelsDir'      => APP_PATH . '/common/models/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'cacheDir'       => BASE_PATH . '/cache/',
        'baseUri'        => '/',
    ],

    'telegram' => array(
        // Add you bot's API key and name
        'api_key'      => '5342562342:AAE1LuntlLHxMja1dc1dW9y9WIfB_Td7mvA', // getenv('TELEGRAM_BOT_TOKEN'),
        'bot_username' => 'EstateBookAdvertBot', // getenv('TELEGRAM_BOT_NAME'),

        // [Manager Only] Secret key required to access the webhook
        'secret'       => 'super_secret',

        'webhook'      => array(
            'url' => 'https://terra.ai-da.store/tgAdmin_webhook.php',
        ),

        // All command related configs go here
        'commands'     => array(
            'paths'   => array(
                APP_PATH . '/modules/TgAdmin/Commands/SystemCommands',
                APP_PATH . '/modules/TgAdmin/Commands/UserCommands',
                APP_PATH . '/modules/TgAdmin/Commands/AdminCommands',
            ),
            // Here you can set any command-specific parameters
            'configs' => array(
                // - Google geocode/timezone API key for /date command (see DateCommand.php)
                // 'date'    => ['google_api_key' => 'your_google_api_key_here'],
                // - OpenWeatherMap.org API key for /weather command (see WeatherCommand.php)
                'weather' => ['owm_api_key' => '3e23a07a6a4883b68e2698af70acb13d'],
                // - Payment Provider Token for /payment command (see Payments/PaymentCommand.php)
                // 'payment' => ['payment_provider_token' => 'your_payment_provider_token_here'],
            )
        ),
        'database' => [
            'adapter'  => 'Mysql',
            'host'     => 'itsige00.mysql.tools', // getenv('DB_TG_HOST'),
            'username' => 'itsige00_ass2tgbot', // getenv('DB_TG_USER'),
            'password' => 'c7X7et#9Z@', //getenv('DB_TG_PASS'),
            'dbname'   => 'itsige00_ass2tgbot', //getenv('DB_TG_NAME'),
            'charset'  => 'utf8',
        ],
        // Define all IDs of admin users
        'admins'       => array(
            1380300520, //EBBot
            522625209, //Юрій
            594711080, //EB
            774997209  //Наталя
        ),

        // Define all IDs of our chats
        'ebChat' => array(
            -1001199609115, // Channel EstateBook Оренда
            -1001422724538, // Channel EstateBook Продаж
            -1001284854614, // Channel EstateBook NEWS
            -1001155920854, // Channel EstateBook CLUB
        ),

        // Logging (Debug, Error and Raw Updates)
        'logging'  => array(
            'debug'  => __DIR__ . '/php-telegram-bot-debug.log',
            'error'  => __DIR__ . '/php-telegram-bot-error.log',
            'update' => __DIR__ . '/php-telegram-bot-update.log',
        ),

        // Set custom Upload and Download paths
        'paths'        => array(
            'download' => BASE_PATH . '/TelegramFiles/Download',
            'upload'   => BASE_PATH . '/TelegramFiles/Upload',
        ),

        // Requests Limiter (tries to prevent reaching Telegram API limits)
        'limiter'      => array(
            'enabled' => true,
        ),
    ),




    /**
     * if true, then we print a new line at the end of each CLI execution
     *
     * If we dont print a new line,
     * then the next command prompt will be placed directly on the left of the output
     * and it is less readable.
     *
     * You can disable this behaviour if the output of your application needs to don't have a new line at end
     */
    'printNewLine' => true
]);
