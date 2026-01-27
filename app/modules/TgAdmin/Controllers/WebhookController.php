<?php

// app/modules/bot/controllers/WebhookController.php
namespace Modules\tgAdmin\Controllers;

use Phalcon\Mvc\Controller;
use Core\ControllerBase;
use Phalcon\Http\Response;

class WebhookController extends Controller
{
    public function indexAction(): Response
    {
        // зчитуємо сирі дані від Telegram
        $raw = file_get_contents('php://input');
        $update = json_decode($raw, true);

        // делегуємо обробку в TelegramService
        $result = $this->di->getShared('telegramService')
            ->handleUpdate($update);

        // Telegram чекає HTTP 200
        $response = new Response();
        return $response->setStatusCode(200, 'OK');
    }
}
