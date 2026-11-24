<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Kernel\Tenant\Model\TenantContext;

/** Read-only native report metadata; never expose interview evidence or report contents. */
final readonly class DiagnosticReportSummaryAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(private DiagnosticRuntimeRepositoryInterface $runtime) {}

    public function binding(): string { return 'query:diagnostic.report.summary.get'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $id = $input['session_id'] ?? null;
        if (array_keys($input) !== ['session_id'] || !is_string($id)
            || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new DomainException('A native session ID is required.');
        }
        $org = $actor->organizationId()->value();
        $session = $this->runtime->get($org, $id);
        if ($session === null || ($session['organization_id'] ?? null) !== $org
            || ($session['session_id'] ?? null) !== $id || ($session['status'] ?? null) !== 'completed') {
            throw new DomainException('Completed Diagnostic session is not available.');
        }
        $report = $this->runtime->report($org, $id);
        $version = $this->positiveInteger($report['report_version'] ?? null);
        $revision = $this->positiveInteger($report['state_revision'] ?? null);
        if ($version < 1 || $revision < 1 || !is_array($report['report'] ?? null)) {
            throw new DomainException('Completed Diagnostic report is not available or is inconsistent.');
        }
        return ['session_id' => $id, 'status' => 'completed', 'report_version' => $version, 'state_revision' => $revision];
    }

    private function positiveInteger(mixed $v): int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : 0;
    }
}
