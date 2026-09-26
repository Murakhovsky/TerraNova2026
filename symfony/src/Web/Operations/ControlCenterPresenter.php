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
                $this->metric('Events', $stats['events'] ?? 0, 'runtime input'),
                $this->metric('Decisions', $stats['decisions'] ?? 0, 'intelligence output'),
                $this->metric('Open actions', $stats['open_actions'] ?? 0, 'execution queue'),
                $this->metric('Approvals', $stats['pending_approvals'] ?? 0, 'human control'),
                $this->metric('Results', $stats['completed_results'] ?? 0, 'completed actions'),
                $this->metric('Dead jobs', $stats['dead_jobs'] ?? 0, 'runtime failures'),
                $this->metric('Active rules', $stats['active_rules'] ?? 0, 'deterministic automation'),
                $this->metric('Agent runs 24h', $stats['agent_runs_24h'] ?? 0, 'AI runtime'),
            ],
            groups: [
                $this->group('events', 'Input', 'Events', 'Останні бізнес-події.', $this->events($overview['events'] ?? null)),
                $this->group('rules', 'Deterministic automation', 'Rules', 'Активні правила виконання.', $this->rules($overview['rules'] ?? null)),
                $this->group('agents', 'Intelligence runtime', 'Agents', 'Останні agent runs.', $this->agents($overview['agent_runs'] ?? null)),
                $this->group('policies', 'Governance', 'Policies', 'Policy decisions і runtime status.', $this->policies($overview['policies'] ?? null)),
                $this->group('integrations', 'Infrastructure boundary', 'Integrations', 'Підключені зовнішні системи.', $this->integrations($overview['integrations'] ?? null)),
                $this->group('decisions', 'Intelligence', 'Decisions', 'Останні AI/deterministic decisions.', $this->decisions($overview['decisions'] ?? null)),
                $this->group('results', 'Execution result', 'Results', 'Результати виконаних actions.', $this->results($overview['results'] ?? null)),
                $this->group('audit', 'Audit', 'Audit trail', 'Операційний журнал виконання.', $this->audit($overview['audit'] ?? null)),
            ],
            actions: $this->actions($overview['actions'] ?? null),
            approvals: $this->approvals($overview['approvals'] ?? null),
            actionStatus: trim($actionStatus),
            error: $error,
        );
    }

    /** @return array{label:string,value:string,hint:string} */
    private function metric(string $label, mixed $value, string $hint): array
    {
        return [
            'label' => $label,
            'value' => (string) (is_numeric($value) ? (int) $value : $value),
            'hint' => $hint,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{key:string,eyebrow:string,label:string,description:string,items:list<array<string,mixed>>}
     */
    private function group(
        string $key,
        string $eyebrow,
        string $label,
        string $description,
        array $items,
    ): array {
        return compact('key', 'eyebrow', 'label', 'description', 'items');
    }

    /** @return list<array<string,mixed>> */
    private function events(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['type'] ?? 'Event'),
                trim((string) ($row['aggregate_type'] ?? '') . ' #' . (string) ($row['aggregate_id'] ?? '')),
                (string) ($row['occurred_at'] ?? ''),
                null,
                'neutral',
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function rules(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['name'] ?? $row['code'] ?? 'Rule'),
                (string) ($row['trigger_type'] ?? ''),
                'priority ' . (string) (int) ($row['priority'] ?? 0),
                (string) ($row['status'] ?? ''),
                $this->tone($row['status'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function agents(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $confidence = $row['confidence'] ?? null;
            $meta = (string) (int) ($row['duration_ms'] ?? 0) . ' ms';
            if (is_numeric($confidence)) {
                $meta .= ' · ' . number_format((float) $confidence * 100, 0) . '%';
            }

            $items[] = $this->item(
                (string) ($row['agent_name'] ?? 'Agent'),
                trim((string) ($row['subject_type'] ?? '') . ' #' . (string) ($row['subject_id'] ?? '')),
                $meta,
                (string) ($row['status'] ?? ''),
                $this->tone($row['status'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function policies(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['name'] ?? $row['code'] ?? 'Policy'),
                (string) ($row['action_type'] ?? ''),
                (string) ($row['decision'] ?? ''),
                (string) ($row['status'] ?? ''),
                $this->tone($row['status'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function integrations(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['name'] ?? 'Integration'),
                trim((string) ($row['provider'] ?? '') . ' · ' . (string) ($row['type'] ?? ''), ' ·'),
                '',
                (string) ($row['status'] ?? ''),
                $this->tone($row['status'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function decisions(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $confidence = is_numeric($row['confidence'] ?? null)
                ? number_format((float) $row['confidence'] * 100, 0) . '%'
                : '';

            $items[] = $this->item(
                (string) ($row['type'] ?? 'Decision'),
                (string) ($row['reason'] ?? 'Причина не вказана.'),
                $confidence,
                (string) ($row['decision'] ?? ''),
                $this->tone($row['decision'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function results(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['action_type'] ?? 'Result'),
                trim((string) ($row['target_type'] ?? '') . ' #' . (string) ($row['target_id'] ?? '')),
                (string) ($row['finished_at'] ?? ''),
                (string) ($row['status'] ?? ''),
                $this->tone($row['status'] ?? ''),
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function audit(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $items[] = $this->item(
                (string) ($row['action'] ?? $row['category'] ?? 'Audit'),
                trim((string) ($row['subject_type'] ?? '') . ' #' . (string) ($row['subject_id'] ?? '')),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['actor_type'] ?? ''),
                'neutral',
            );
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function actions(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $status = (string) ($row['status'] ?? '');
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['type'] ?? 'Action'),
                'target' => trim((string) ($row['target_type'] ?? '') . ' #' . (string) ($row['target_id'] ?? '')),
                'status' => $status,
                'tone' => $this->tone($status),
                'risk' => (string) ($row['risk_level'] ?? ''),
                'canExecute' => $status === 'QUEUED',
                'approvalId' => (string) ($row['approval_id'] ?? ''),
                'approvalStatus' => (string) ($row['approval_status'] ?? ''),
            ];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function approvals(mixed $source): array
    {
        $items = [];
        foreach ($this->list($source) as $row) {
            $status = (string) ($row['status'] ?? '');
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['action_type'] ?? 'Approval'),
                'target' => trim((string) ($row['target_type'] ?? '') . ' #' . (string) ($row['target_id'] ?? '')),
                'status' => $status,
                'tone' => $this->tone($status),
                'risk' => (string) ($row['risk_level'] ?? ''),
                'reason' => (string) ($row['reason'] ?? 'Потрібне рішення менеджера.'),
                'canDecide' => $status === 'PENDING',
            ];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function item(
        string $title,
        string $subtitle,
        string $meta,
        ?string $statusLabel,
        string $statusTone,
    ): array {
        return compact('title', 'subtitle', 'meta', 'statusLabel', 'statusTone');
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
