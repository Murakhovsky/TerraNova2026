<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class CabinetController extends ControllerBase
{
    public function indexAction(): void
    {
        $user = $this->requireUser();

        if (!$user) {
            return;
        }

        $this->view->title = 'Кабінет';
        $this->view->user = $user;
        $this->view->isManager = $this->authService()->isManager($user);
        $this->view->submissions = [];
        $this->view->inboundRequests = [];
        $this->view->pageStatus = null;

        try {
            $data = $this->authService()->cabinetData($user);
            $this->view->submissions = $data['submissions'];
            $this->view->inboundRequests = $data['inbound_requests'];
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-page', $e);
            $this->view->pageStatus = 'Дані кабінету тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.';
        }
    }
}
