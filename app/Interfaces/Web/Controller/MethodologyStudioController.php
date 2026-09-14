<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;

final class MethodologyStudioController extends ControllerBase
{
    public function indexAction(): void
    {
        $user = $this->requireUser();
        if (!$user) return;

        $organizationId = $this->di->getShared('organizationContext')->id();
        $access = $this->di->getShared('diagnosticMethodologyAccess');
        if (!$access->allows($organizationId, (int) $user['id'], DiagnosticMethodologyAccess::VIEW)) {
            $this->response->setStatusCode(403, 'Forbidden');
            return;
        }

        $this->view->metaTitle = 'Diagnostic Methodology Studio | COS';
        $this->view->active = 'diagnostics';
        $this->view->pageAssetEntries = ['diagnostics-methodology-studio'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
    }
}
