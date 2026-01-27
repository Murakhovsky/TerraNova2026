<?php
namespace Modules\Games\Template\Controllers;

use Phalcon\Mvc\Controller;
use Common\UI\UIRenderService;          // твій універсальний рендер
use Common\UI\Descriptor;             // HeaderDescriptor, ObjectBlockDescriptor, ButtonDescriptor

class GameController extends ControllerBase
{
    public function playAction()
    {
        $dtoList = $this->di->getShared('templateService')->getChallenge($this->userId());
        $output  = $this->di->getShared(UIRenderService::class)
            ->render($dtoList);
        return $this->response->setJsonContent($output);
    }

    public function submitAction()
    {
        $data    = $this->request->getJsonRawBody(true);
        $result  = $this->di->getShared('templateService')
            ->checkAnswer($this->userId(), $data['id'], $data['answer']);
        // повернемо стандартний descriptor для результату
        $dtoList = [ new HeaderDescriptor($result ? 'Правильно!' : 'Ні, спробуй ще') ];
        $output  = $this->di->getShared(UIRenderService::class)->render($dtoList);
        return $this->response->setJsonContent($output);
    }

    public function leaderboardAction(): Response
    {
        return $this->response->setJsonContent(
            $this->di->getShared('gameService')->getLeaderboard()
        );
    }
}
