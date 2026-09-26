<?php

declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Operations\ViewModel\ControlCenterViewModel;

final class ControlCenterPresenter
{
    /** @param array<string,mixed> $overview */
    public function present(
        array $overview,
        string $actionStatus = '',
        ?string $error = null,
    ): ControlCenterViewModel {
        $stats = $this->array($overview['stats'] ?? null);

        return new ControlCenterViewModel(
            kpis: [
                $this->kpi('Events', $stats['events'] ?? 0),
                $this->kpi('Decisions', $stats['decisions'] ?? 0),
                $this->kpi('Open Actions', $stats['open_actions'] ?? 0, 'execution queue', 'info'),
                $this->kpi('Approvals', $stats['pending_approvals'] ?? 0, 'human decisions', ((int) ($stats['pending_approvals'] ?? 0)) > 0 ? 'warning' : 'neutral'),
                $this->kpi('Results', $stats['completed_results'] ?? 0, 'completed', 'positive'),
                $this->kpi('Dead jobs', $stats['dead_jobs'] ?? 0, 'runtime failures', ((int) ($stats['dead_jobs'] ?? 0)) > 0 ? 'danger' : 'neutral'),
                $this->kpi('Active Rules', $stats['active_rules'] ?? 0),
                $this->kpi('Agent runs 24h', $stats['agent_runs_24h'] ?? 0),
            ],
            sections: [
                $this->section('events', 'Input', 'Events', 'Останні бізнес-події.', $this->events($overview['events'] ?? null)),
                $this->section('rules', 'Deterministic automation', 'Rules', 'Активні правила та їх конфігурація.', $this->rules($overview['rules'] ?? null)),
                $this->section('agents', 'Intelligence runtime', 'Agents', 'Останні agent runs.', $this->agents($overview['agent_runs'] ?? null)),
                $this->section('policies', 'Governance', 'Policies', 'Policy decisions для контрольованих дій.', $this->policies($overview['policies'] ?? null)),
                $this->section('integrations', 'Infrastructure boundary', 'Integrations', 'Стан підключених інтеграцій.', $this->integrations($overview['integrations'] ?? null)),
                $this->section('decisions', 'Intelligence', 'Decisions', 'Останні рішення COS.', $this->decisions($overview['decisions'] ?? null)),
                $this->section('actions', 'Execution intent', 'Proposed Actions', 'Запропоновані або queued дії.', $this->actions($overview['actions'] ?? null)),
                $this->section('approvals', 'Human control', 'Approvals', 'Рішення, які очікують людину.', $this->approvals($overview['approvals'] ?? null)),
                $this->section('results', 'Outcome', 'Results', 'Результати execution attempts.', $this->results($overview['results'] ?? null)),
                $this->section('audit', 'Traceability', 'Audit', 'Останні audit records.', $this->audit($overview['audit'] ?? null)),
            ],
            actionStatus: $actionStatus,
            error: $error,
        );
    }

    /** @return array{label:string,value:string,hint?:string,tone?:string} */
    private function kpi(string $label, mixed $value, string $hint = '', string $tone = 'neutral'): array
    {
        $row = ['label' => $label, 'value' => (string) $value, 'tone' => $tone];
        if ($hint !== '') {
            $row['hint'] = $hint;
        }

        return $row;
    }

    /** @param list<array<string,mixed>> $items @return array{id:string,eyebrow:string,title:string,description:string,items:list<array<string,mixed>>} */
    private function section(string $id, string $eyebrow, string $title, string $description, array $items): array
    {
        return compact('id', 'eyebrow', 'title', 'description', 'items');
    }

    /** @return list<array<string,mixed>> */
    private function events(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            (string) ($item['type'] ?? 'Event'),
            trim((string) (($item['aggregate_type'] ?? '') . ' #' . ($item['aggregate_id'] ?? '')), ' #'),
            (string) ($item['occurred_at'] ?? ''),
            null,
            'neutral',
            $this->json($item['payload'] ?? null),
        ), $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function rules(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            (string) ($item['name'] ?? $item['code'] ?? 'Rule'),
            (string) ($item['trigger_type'] ?? ''),
            'Priority ' . (string) (int) ($item['priority'] ?? 0),
            (string) ($item['status'] ?? ''),
            $this->tone($item['status'] ?? ''),
            $this->json($item['effect'] ?? null),
        ), $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function agents(mixed $source): array
    {
        return array_map(function (array $item): array {
            $confidence = $item['confidence'] ?? null;
            return $this->item(
                (string) ($item['agent_name'] ?? 'Agent'),
                trim((string) (($item['agent_version'] ?? '') . ' · ' . ($item['model'] ?? '')), ' ·'),
                ($confidence !== null ? round((float) $confidence * 100) . '% · ' : '') . (string) ((int) ($item['duration_ms'] ?? 0)) . ' ms',
                (string) ($item['status'] ?? ''),
                $this->tone($item['status'] ?? ''),
            );
        }, $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function policies(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            (string) ($item['name'] ?? $item['code'] ?? 'Policy'),
            (string) ($item['action_type'] ?? ''),
            (string) ($item['decision'] ?? ''),
            (string) ($item['status'] ?? ''),
            $this->tone($item['status'] ?? ''),
        ), $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function integrations(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            (string) ($item['name'] ?? 'Integration'),
            trim((string) (($item['provider'] ?? '') . ' · ' . ($item['type'] ?? '')), ' ·'),
            '',
            (string) ($item['status'] ?? ''),
            $this->tone($item['status'] ?? ''),
        ), $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function decisions(mixed $source): array
    {
        return array_map(function (array $item): array {
            $confidence = round((float) ($item['confidence'] ?? 0) * 100);
            return $this->item(
                (string) ($item['type'] ?? 'Decision'),
                (string) ($item['reason'] ?? 'Причина не вказана.'),
                $confidence . '% · ' . trim((string) (($item['agent_name'] ?? '') . ' ' . ($item['model'] ?? ''))),
                (string) ($item['decision'] ?? ''),
                $this->tone($item['decision'] ?? ''),
            );
        }, $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function actions(mixed $source): array
    {
        return array_map(function (array $item): array {
            $status = (string) ($item['status'] ?? '');
            return $this->item(
                (string) ($item['type'] ?? 'Action'),
                trim((string) (($item['target_type'] ?? 'target') . ' #' . ($item['target_id'] ?? '—'))),
                'Risk: ' . (string) ($item['risk_level'] ?? '—'),
                $status,
                $this->tone($status),
                $this->json($item['parameters'] ?? null),
                [
                    'executeUrl' => $status === 'QUEUED' ? '/cos/action/' . (string) ($item['id'] ?? '') . '/execute' : null,
                    'approvalHref' => $status === 'PENDING_APPROVAL' && ($item['approval_status'] ?? '') === 'PENDING'
                        ? '#approval-' . (string) ($item['approval_id'] ?? '')
                        : null,
                ],
            );
        }, $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function approvals(mixed $source): array
    {
        return array_map(function (array $item): array {
            $status = (string) ($item['status'] ?? '');
            $id = (string) ($item['id'] ?? '');
            return $this->item(
                (string) ($item['action_type'] ?? 'Approval'),
                (string) ($item['reason'] ?? 'Потрібне рішення менеджера.'),
                trim((string) (($item['target_type'] ?? 'target') . ' #' . ($item['target_id'] ?? '—'))),
                $status,
                $this->tone($status),
                null,
                [
                    'anchor' => 'approval-' . $id,
                    'approveUrl' => $status === 'PENDING' ? '/cos/approval/' . $id . '/approve' : null,
                    'rejectUrl' => $status === 'PENDING' ? '/cos/approval/' . $id . '/reject' : null,
                ],
            );
        }, $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function results(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            (string) ($item['action_type'] ?? 'Result'),
            trim((string) (($item['target_type'] ?? 'target') . ' #' . ($item['target_id'] ?? '—'))),
            (string) ($item['finished_at'] ?? ''),
            (string) ($item['status'] ?? ''),
            $this->tone($item['status'] ?? ''),
            (string) (($item['error'] ?? '') ?: $this->json($item['result'] ?? null)),
        ), $this->list($source));
    }

    /** @return list<array<string,mixed>> */
    private function audit(mixed $source): array
    {
        return array_map(fn (array $item): array => $this->item(
            trim((string) (($item['action'] ?? 'recorded') . ' · ' . ($item['subject_type'] ?? '') . ' #' . ($item['subject_id'] ?? ''))),
            (string) (($item['reason'] ?? '') ?: (($item['actor_type'] ?? '') . ' ' . ($item['actor_id'] ?? ''))),
            (string) ($item['created_at'] ?? ''),
            (string) ($item['category'] ?? ''),
            $this->tone($item['category'] ?? ''),
        ), $this->list($source));
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function item(
        string $title,
        string $subtitle,
        string $meta = '',
        ?string $statusLabel = null,
        string $statusTone = 'neutral',
        ?string $details = null,
        array $extra = [],
    ): array {
        return array_replace([
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'statusLabel' => $statusLabel,
            'statusTone' => $statusTone,
            'details' => $details,
        ], $extra);
    }

    private function tone(mixed $status): string
    {
        $value = strtoupper(str_replace('-', '_', trim((string) $status)));

        return match (true) {
            in_array($value, ['ACTIVE', 'ENABLED', 'ALLOW', 'APPROVED', 'EXECUTED', 'COMPLETED', 'SUCCESS', 'SUCCEEDED', 'WON', 'CONNECTED'], true) => 'positive',
            in_array($value, ['FAILED', 'ERROR', 'REJECTED', 'DENY', 'DENIED', 'DEAD', 'BLOCKED', 'CANCELLED'], true) => 'danger',
            in_array($value, ['PENDING', 'PENDING_APPROVAL', 'QUEUED', 'RETRY', 'WARNING'], true) => 'warning',
            in_array($value, ['RUNNING', 'PROPOSED', 'PROCESSING', 'INFO'], true) => 'info',
            default => 'neutral',
        };
    }

    private function json(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $normalized = json_last_error() === JSON_ERROR_NONE || !is_string($value) ? $decoded : $value;

        return (string) json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
