<?php
declare(strict_types=1);

namespace Interfaces\Telegram\Controller;

use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

class WebhookController extends Controller
{
    public function indexAction(): Response
    {
        $expectedSecret = (string) $this->di->getShared('config')->telegram->secret;
        $providedSecret = (string) $this->request->getHeader('X-Telegram-Bot-Api-Secret-Token');
        if ($expectedSecret !== '' && !hash_equals($expectedSecret, $providedSecret)) {
            return (new Response())->setStatusCode(403, 'Forbidden');
        }

        $this->di->getShared('telegramBot')->handle();

        return (new Response())->setStatusCode(200, 'OK');
    }
}
