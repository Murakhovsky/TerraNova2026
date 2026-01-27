<?php

/**
 * This file is part of the PHP Telegram Bot example-bot package.
 * https://github.com/php-telegram-bot/example-bot/
 *
 * (c) PHP Telegram Bot Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * User "/weather" command
 *
 * Get weather info for the location passed as the parameter..
 *
 * A OpenWeatherMap.org API key is required for this command!
 * You can be set in your config.php file:
 * ['commands']['configs']['weather'] => ['owm_api_key' => 'your_owm_api_key_here']
 */

namespace Modules\TgAdmin\Commands\UserCommands;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use Telegram\Featuring\Messages;

class WeatherCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'weather';

    /**
     * @var string
     */
    protected $description = 'Show weather by location';

    /**
     * @var string
     */
    protected $usage = '/weather';

    /**
     * @var string
     */
    protected $version = '1.3.0';

    /**
     * Base URI for OpenWeatherMap API
     *
     * @var string
     */
    private $owm_api_base_uri = 'http://api.openweathermap.org/data/2.5/';


    private $owm_api_key = "3e23a07a6a4883b68e2698af70acb13d";

    /**
     * Get weather data using HTTP request
     *
     * @param string $location
     *
     * @return string
     */
    private function getWeatherData($location): string
    {
        $client = new Client(['base_uri' => $this->owm_api_base_uri]);
        $path   = 'weather';
        $query  = [
            'q'     => $location,
            'units' => 'metric',
//            'APPID' => trim($this->getConfig('owm_api_key')),
            'APPID' => $this->owm_api_key,
            'lang' => 'ua'
        ];

        try {
            $response = $client->get($path, ['query' => $query]);
        } catch (RequestException $e) {
            TelegramLog::error($e->getMessage());

            return '';
        }

        return (string) $response->getBody();
    }

    /**
     * Get weather string from weather data
     *
     * @param array $data
     *
     * @return string
     */
    private function getWeatherString(array $data): string
    {
        try {
            if (!(isset($data['cod']) && $data['cod'] === 200)) {
                return '';
            }

            //http://openweathermap.org/weather-conditions
            $conditions     = [
                'clear'        => ' ☀️',
                'clouds'       => ' ☁️',
                'rain'         => ' ☔',
                'drizzle'      => ' ☔',
                'thunderstorm' => ' ⚡️',
                'snow'         => ' ❄️',
            ];
            $conditions_now = strtolower($data['weather'][0]['main']);

            return sprintf(
                'Температура у Місті %s (%s) становить %s°C' . PHP_EOL .
                'Погодні явища: %s%s',
                $data['name'], //city
                $data['sys']['country'], //country
                $data['main']['temp'], //temperature
                $data['weather'][0]['description'], //description of weather
                $conditions[$conditions_now] ?? ''
            );
        } catch (Exception $e) {
            TelegramLog::error($e->getMessage());

            return '';
        }
    }

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $callback = $this->getCallbackQuery();

        if (!empty($message)){
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user_name = $message->getFrom()->getUsername();
            $message_id = $message->getMessageId();
        }
        if(!empty($callback)) {

            $user_id = $callback->getFrom()->getId();
            $user_name = $callback->getFrom()->getUsername();
            if ($callback->getMessage()) {
                $message_id = $callback->getMessage()->getMessageId();
            }
            $data = $callback->getData();
            $page = explode(';', $data)[1];
        }

        // Check to make sure the required OWM API key has been defined.
//        $owm_api_key = $this->getConfig('owm_api_key');

        if (empty($this->owm_api_key)) {
            return $this->replyToChat('OpenWeatherMap API key not defined.');
        }

//        $location = trim($this->getMessage()->getText(true));

        // Only fore Lviv
        $location = 'Львів';

        if ($location === '') {
            return $this->replyToChat('You must specify a location as: ' . $this->getUsage());
        }

        $text = 'Cannot find weather for location: ' . $location;
        if ($weather_data = json_decode($this->getWeatherData($location), true)) {
            $text = $this->getWeatherString($weather_data);
        }

        return Request::sendMessage([
            'chat_id' => $user_id,
            'text' => $text
        ]);

//        return $this->replyToChat($text);
    }
}