<?php
declare(strict_types=1);

namespace Modules\Games\Controllers;
use Longman\TelegramBot\Request;
use Modules\Users\Models\Person\AppUsers;
use Phalcon\Mvc\Controller;

class IndexController extends Controller
{

    public function indexAction()
    {
        $user = new AppUsers();
//        $user->edit(array("test" => "test1"));

        $game = $_GET["game"];
        switch ($game){
            case "PaintIO":
            {
                echo "<iframe src='https://www.miniplay.com/embed/paint-io' style='width:100%;height:100%;' frameborder='0' allowfullscreen></iframe>";
                break;
            }
        }

    }

}

