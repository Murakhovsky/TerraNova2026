<?php
/*
 * Modified: prepend directory path of current file, because of this file own different ENV under between Apache and command line.
 * NOTE: please remove this comment.
 */
defined('BASE_PATH') || define('BASE_PATH', getenv('BASE_PATH') ?: realpath(dirname(__FILE__) . '/../..'));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');
$httpHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
defined('DOMAIN_NAME') || define('DOMAIN_NAME', $scheme . '://' . $httpHost);

return new \Phalcon\Config\Config([
    'version' => '1.0',

    'database' => [
        'adapter'  => 'Mysql',
        'host'     => (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'mysql'),
        'port'     => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
        'username' => (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'cos'),
        'password' => (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
        'dbname'   => (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'cos'),
        'charset'  => 'utf8mb4',
    ],

    'application' => [
        'appDir'         => APP_PATH . '/',
        'modelsDir'      => APP_PATH . '/Infrastructure/Persistence/Phalcon/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'cacheDir'       => BASE_PATH . '/cache/',
        'baseUri'        => '/',
        'publicUrl'      => rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: DOMAIN_NAME), '/'),
    ],

    'cos' => [
        'organizationId' => getenv('COS_ORGANIZATION_ID') ?: 'default',
    ],

    'llm' => [
        'endpoint' => getenv('LLM_ENDPOINT') ?: '',
        'token' => getenv('LLM_TOKEN') ?: '',
        'model' => getenv('LLM_MODEL') ?: '',
        'provider' => getenv('LLM_PROVIDER') ?: 'http',
    ],

    'agent' => [
        'inputRetentionDays' => (int) (getenv('AGENT_INPUT_RETENTION_DAYS') ?: 30),
    ],

    'integrations' => [
        'n8n' => [
            'inbound_secret' => (string) ($_ENV['N8N_WEBHOOK_SECRET'] ?? getenv('N8N_WEBHOOK_SECRET') ?: ''),
            'outbound_url' => (string) ($_ENV['N8N_OUTBOUND_URL'] ?? getenv('N8N_OUTBOUND_URL') ?: ''),
            'outbound_secret' => (string) ($_ENV['N8N_OUTBOUND_SECRET'] ?? getenv('N8N_OUTBOUND_SECRET') ?: ''),
            'max_clock_skew' => (int) ($_ENV['N8N_MAX_CLOCK_SKEW'] ?? getenv('N8N_MAX_CLOCK_SKEW') ?: 300),
        ],
    ],

    'spatial' => [
        'max_upload_bytes' => (int) ($_ENV['SPATIAL_MAX_UPLOAD_BYTES'] ?? getenv('SPATIAL_MAX_UPLOAD_BYTES') ?: 209715200),
        'jwt_secret' => (string) ($_ENV['SPATIAL_JWT_SECRET'] ?? getenv('SPATIAL_JWT_SECRET') ?: ''),
        'jwt_ttl' => (int) ($_ENV['SPATIAL_JWT_TTL'] ?? getenv('SPATIAL_JWT_TTL') ?: 28800),
        'blender_binary' => (string) ($_ENV['SPATIAL_BLENDER_BINARY'] ?? getenv('SPATIAL_BLENDER_BINARY') ?: ''),
        'gltf_transform_binary' => (string) ($_ENV['SPATIAL_GLTF_TRANSFORM_BINARY'] ?? getenv('SPATIAL_GLTF_TRANSFORM_BINARY') ?: ''),
    ],

    'telegram' => array(
        // Add you bot's API key and name
        'api_key'      => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'bot_username' => getenv('TELEGRAM_BOT_NAME') ?: '',

        // [Manager Only] Secret key required to access the webhook
        'secret'       => getenv('TELEGRAM_WEBHOOK_SECRET') ?: '',

        'webhook'      => array(
            'url' => (string) ($_ENV['TELEGRAM_WEBHOOK_URL'] ?? getenv('TELEGRAM_WEBHOOK_URL') ?: 'https://terra.ai-da.store/tgAdmin_webhook.php'),
        ),

        // All command related configs go here
        'commands'     => array(
            'paths'   => array(
                APP_PATH . '/Interfaces/Telegram/Command/SystemCommands',
                APP_PATH . '/Interfaces/Telegram/Command/UserCommands',
            ),
            // Here you can set any command-specific parameters
            'configs' => array(
                // - Google geocode/timezone API key for /date command (see DateCommand.php)
                // 'date'    => ['google_api_key' => 'your_google_api_key_here'],
                // - OpenWeatherMap.org API key for /weather command (see WeatherCommand.php)
                'weather' => ['owm_api_key' => getenv('OPENWEATHER_API_KEY') ?: ''],
                // - Payment Provider Token for /payment command (see Payments/PaymentCommand.php)
                // 'payment' => ['payment_provider_token' => 'your_payment_provider_token_here'],
            )
        ),
        'database' => [
            'adapter'  => 'Mysql',
            'host'     => getenv('DB_TG_HOST') ?: 'mysql',
            'port'     => (int) (getenv('DB_TG_PORT') ?: (getenv('DB_PORT') ?: 3306)),
            'username' => getenv('DB_TG_USERNAME') ?: (getenv('DB_USERNAME') ?: 'cos'),
            'password' => getenv('DB_TG_PASSWORD') ?: (getenv('DB_PASSWORD') ?: ''),
            'dbname'   => getenv('DB_TG_DATABASE') ?: (getenv('DB_DATABASE') ?: 'cos'),
            'charset'  => 'utf8mb4',
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
