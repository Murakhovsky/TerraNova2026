<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Throwable;

final class DiagnosticReportController extends ControllerBase
{
    public function showAction(?string $session = null): void
    {
        if ($this->requireUser() === null) {
            return;
        }

        $sessionId = (string) ($session ?: $this->dispatcher->getParam('session'));
        try {
            /** @var DiagnosticRuntimeService $runtime */
            $runtime = $this->di->getShared('diagnosticRuntimeService');
            $envelope = $runtime->report($this->di->getShared('organizationContext')->id(), $sessionId);
        } catch (Throwable $exception) {
            $this->response->setStatusCode(404, 'Not Found');
            $this->view->disable();
            $this->response->setContent('Diagnostic report was not found.');
            return;
        }

        $this->view->metaTitle = 'Diagnostic Report | COS';
        $this->view->active = 'diagnostics';
        $this->view->sessionId = $sessionId;
        $this->view->reportEnvelope = $envelope;
        $this->view->report = is_array($envelope['report'] ?? null) ? $envelope['report'] : [];
    }
}
