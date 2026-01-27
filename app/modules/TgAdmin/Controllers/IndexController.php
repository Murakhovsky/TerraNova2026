<?php
declare(strict_types=1);

namespace Modules\tgAdmin\Controllers;
use Longman\TelegramBot\Request;
use Phalcon\Mvc\Controller;
use Modules\TgAdmin\TelegramBotEB;

class IndexController extends Controller
{

    public function indexAction()
    {
        $telegram = new TelegramBotEB(
            $this->di["config"]["telegram"],
            $this->di["config"]["telegram"]["database"],
            $this->di
        );
        return $telegram->handle();
    }

}

