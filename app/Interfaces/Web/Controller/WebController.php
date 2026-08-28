<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Identity\Application\Contract\AuthenticatedUserContextInterface;
use Interfaces\Web\Security\CsrfTokenManager;
use Kernel\Tenant\OrganizationContextInterface;
use Phalcon\Mvc\Controller;

abstract class WebController extends Controller
{
    protected function auth(): AuthenticatedUserContextInterface
    {
        return $this->di->getShared('authService');
    }

    protected function organization(): OrganizationContextInterface
    {
        return $this->di->getShared('organizationContext');
    }

    protected function requireManager(): ?array
    {
        $user = $this->auth()->currentUser();
        if ($user === null) {
            $this->response->redirect('auth/login');
            return null;
        }
        if (!$this->auth()->isManager($user)) {
            $this->response->setStatusCode(403, 'Forbidden');
            return null;
        }
        return $user;
    }

    protected function validMutation(): bool
    {
        if (!$this->request->isPost()) {
            return false;
        }
        /** @var CsrfTokenManager $csrf */
        $csrf = $this->di->getShared('csrfTokenManager');
        $token = (string) ($this->request->getPost('csrf_token', 'string', '')
            ?: $this->request->getHeader('X-CSRF-Token'));
        return $csrf->verify($token);
    }
}
