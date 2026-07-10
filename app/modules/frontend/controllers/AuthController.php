<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

class AuthController extends ControllerBase
{
    public function loginAction(): void
    {
        $this->view->title = 'Вхід';
        $this->view->authStatus = null;
        $this->view->formData = (array) $this->request->getPost();

        if ($this->currentUser()) {
            $this->response->redirect('cabinet');
            return;
        }

        if ($this->request->isPost()) {
            $result = $this->authService()->login((array) $this->request->getPost());
            $this->view->authStatus = $result['message'];

            if ($result['ok']) {
                $this->response->redirect('cabinet');
            }
        }
    }

    public function registerAction(): void
    {
        $this->view->title = 'Реєстрація';
        $this->view->authStatus = null;
        $this->view->formData = (array) $this->request->getPost();

        if ($this->currentUser()) {
            $this->response->redirect('cabinet');
            return;
        }

        if ($this->request->isPost()) {
            $result = $this->authService()->register((array) $this->request->getPost());
            $this->view->authStatus = $result['message'];

            if ($result['ok']) {
                $this->response->redirect('cabinet');
            }
        }
    }

    public function logoutAction(): void
    {
        $this->authService()->logout();
        $this->response->redirect('');
    }
}
