<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

/**
 * Manager-owned, evidence-bound recovery context for independent Security reviews.
 *
 * Specialist findings are advisory constraints, never an Architect approval.
 * Legacy reports without an explicit architecture artifact binding stay UNVERIFIED.
 */
final readonly class EngineeringArchitectSecurityRecoveryContext
{
    /** @param array<string,mixed>|null $report @return array<string,mixed>|null */
    public function fromArtifact(?array $report, string $repositoryRevision): ?array
    {
        $content = is_array($report['content'] ?? null) ? $report['content'] : null;
        $status = strtoupper(trim((string) ($content['status'] ?? '')));
        if ($content === null || !in_array($status, ['REQUEST_CHANGES', 'ARCHITECTURE_REVIEW_REQUIRED'], true)) {
            return null;
        }

        $findings = [];
        foreach (is_array($content['findings'] ?? null) ? $content['findings'] : [] as $finding) {
            if (!is_array($finding)) continue;
            $id = trim((string) ($finding['id'] ?? ''));
            if ($id === '' || isset($findings[$id])) continue;
            $findings[$id] = [
                'id' => $id,
                'severity' => strtoupper(trim((string) ($finding['severity'] ?? 'UNSPECIFIED'))),
                'title' => (string) ($finding['title'] ?? ''),
                'classification' => (string) ($finding['classification'] ?? ''),
                'risk' => (string) ($finding['risk'] ?? ''),
                'required_resolution' => (string) ($finding['required_resolution'] ?? ''),
                'evidence' => is_array($finding['evidence'] ?? null)
                    ? array_values(array_filter($finding['evidence'], 'is_string'))
                    : [],
                'resolution_state' => 'UNVERIFIED',
            ];
        }

        $actions = [];
        foreach (is_array($content['required_actions'] ?? null) ? $content['required_actions'] : [] as $action) {
            if (!is_array($action)) continue;
            $id = trim((string) ($action['finding_id'] ?? ''));
            if ($id === '' || !isset($findings[$id])) continue;
            $actions[] = [
                'id' => (string) ($action['id'] ?? ''),
                'finding_id' => $id,
                'action' => (string) ($action['action'] ?? ''),
                'timing' => (string) ($action['timing'] ?? ''),
            ];
        }

        if ($findings === []) return null;

        $reviewedRevision = trim((string) ($content['reviewed_revision'] ?? ''));
        $architectureBinding = trim((string) ($content['reviewed_architecture_content_hash'] ?? ''));
        return [
            'kind' => 'TARGETED_SECURITY_REVIEW_RECOVERY',
            'source_artifact_id' => (string) ($report['id'] ?? ''),
            'source_artifact_hash' => (string) ($report['content_hash'] ?? ''),
            'source_artifact_status' => (string) ($report['status'] ?? 'UNKNOWN'),
            'review_is_historical' => strtoupper((string) ($report['status'] ?? '')) !== 'ACTIVE',
            'source_status' => $status,
            'source_phase' => (string) ($content['phase'] ?? $content['evidence']['phase'] ?? ''),
            'reviewed_revision' => $reviewedRevision,
            'current_repository_revision' => $repositoryRevision,
            'revision_binding' => $reviewedRevision !== '' && $reviewedRevision === $repositoryRevision
                ? 'MATCH' : 'UNVERIFIED_OR_STALE',
            'reviewed_architecture_content_hash' => $architectureBinding !== '' ? $architectureBinding : null,
            'architecture_binding' => $architectureBinding !== '' ? 'EXPLICIT' : 'UNVERIFIED_LEGACY',
            'findings' => array_values($findings),
            'required_actions' => $actions,
            'finding_ids' => array_keys($findings),
            'independent_security_recheck_required' => true,
            'development_authorized' => false,
            'instructions' => [
                'Resolve each finding using primary repository evidence at the pinned revision.',
                'Treat reviews of earlier architecture versions as historical findings; verify applicability to the current proposal.',
                'No Architect self-approval closes a specialist finding: independent Security Specialist recheck is mandatory.',
                'Do not invent authentication, route, cache or test contracts and do not request a human to fetch ordinary repository files.',
            ],
        ];
    }
}
