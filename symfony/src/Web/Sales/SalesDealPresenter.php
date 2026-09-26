<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesDealViewModel;

final class SalesDealPresenter
{
    /** @param array<string,mixed> $data */
    public function present(array $data, int $fallbackId, ?string $error = null): SalesDealViewModel
    {
        $deal = $this->array($data['deal'] ?? null);
        $id = max(1, (int) ($deal['id'] ?? $fallbackId));
        $risk = strtolower(trim((string) ($deal['risk_level'] ?? '')));
        $statusTone = match ($risk) {
            'high', 'critical' => 'danger',
            'medium', 'attention' => 'warning',
            'low', 'healthy' => 'positive',
            default => 'neutral',
        };
        $statusLabel = $risk !== '' ? strtoupper($risk) : 'ACTIVE';

        $pipelineName = (string) ($deal['pipeline_name'] ?? '—');
        $stageName = (string) ($deal['stage_name'] ?? $deal['stage_code'] ?? '—');
        $ownerName = trim((string) ($deal['owner_name'] ?? ''));
        if ($ownerName === '') {
            $ownerName = 'Unassigned';
        }
        $currency = trim((string) ($deal['currency'] ?? 'USD')) ?: 'USD';

        $stages = [];
        foreach ($this->list($data['pipelines'] ?? null) as $pipeline) {
            if ((string) ($pipeline['id'] ?? '') !== (string) ($deal['pipeline_id'] ?? '')) {
                continue;
            }
            foreach ($this->list($pipeline['stages'] ?? null) as $stage) {
                $stageId = (string) ($stage['id'] ?? '');
                if ($stageId === '') {
                    continue;
                }
                $stages[] = [
                    'id' => $stageId,
                    'label' => (string) ($stage['name'] ?? $stage['code'] ?? 'Stage'),
                    'selected' => $stageId === (string) ($deal['stage_id'] ?? ''),
                ];
            }
            break;
        }

        $owners = [];
        foreach ($this->list($data['owners'] ?? null) as $owner) {
            if (
                strtolower((string) ($owner['status'] ?? '')) !== 'active'
                || !in_array(
                    strtolower((string) ($owner['organization_role'] ?? $owner['role'] ?? '')),
                    ['manager', 'admin'],
                    true,
                )
            ) {
                continue;
            }

            $ownerId = (int) ($owner['id'] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }

            $label = trim((string) ($owner['full_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($owner['email'] ?? ''));
            }

            $owners[] = [
                'id' => $ownerId,
                'label' => $label !== '' ? $label : ('#' . $ownerId),
                'selected' => $ownerId === (int) ($deal['assigned_user_id'] ?? 0),
            ];
        }

        $communications = [];
        foreach ($this->list($data['communications'] ?? null) as $item) {
            $communications[] = [
                'direction' => strtolower((string) ($item['direction'] ?? 'inbound')),
                'channel' => (string) ($item['channel'] ?? 'WEB'),
                'body' => (string) ($item['body'] ?? ''),
                'occurredAt' => (string) ($item['occurred_at'] ?? ''),
            ];
        }

        $approvals = [];
        foreach ($this->list($data['approvals'] ?? null) as $approval) {
            $approvalId = (string) ($approval['approval_id'] ?? '');
            if ($approvalId === '') {
                continue;
            }
            $approvals[] = [
                'id' => $approvalId,
                'type' => (string) ($approval['action_type'] ?? 'Action'),
                'reason' => (string) ($approval['approval_reason'] ?? 'Approval required'),
            ];
        }

        $timeline = [];
        foreach ($this->list($data['timeline'] ?? null) as $item) {
            $timeline[] = [
                'tone' => $this->timelineTone((string) ($item['status'] ?? '')),
                'time' => (string) ($item['occurred_at'] ?? ''),
                'title' => (string) ($item['title'] ?? $item['subtype'] ?? 'Event'),
                'copy' => (string) ($item['detail'] ?? ''),
            ];
        }

        $intelligence = $this->normalizeIntelligence($this->array($data['intelligence'] ?? null));
        $confidence = $intelligence['confidence'] ?? null;
        $confidenceLabel = '—';
        if (is_numeric($confidence)) {
            $value = (float) $confidence;
            if ($value <= 1) {
                $value *= 100;
            }
            $confidenceLabel = number_format($value, 0) . '%';
        } elseif ($confidence !== null && $confidence !== '') {
            $confidenceLabel = (string) $confidence;
        }

        return new SalesDealViewModel(
            id: $id,
            identity: (string) ($deal['public_id'] ?? ('#' . $id)),
            title: (string) ($deal['customer'] ?? $deal['title'] ?? ('Deal #' . $id)),
            subtitle: (string) ($deal['title'] ?? ''),
            statusLabel: $statusLabel,
            statusTone: $statusTone,
            meta: [
                ['label' => 'Pipeline', 'value' => $pipelineName],
                ['label' => 'Stage', 'value' => $stageName],
                ['label' => 'Owner', 'value' => $ownerName],
                ['label' => 'Value', 'value' => $this->money($deal['value'] ?? 0, $currency)],
            ],
            kpis: [
                ['label' => 'Value', 'value' => $this->money($deal['value'] ?? 0, $currency)],
                ['label' => 'Weighted', 'value' => $this->money($deal['weighted_value'] ?? 0, $currency)],
                ['label' => 'Probability', 'value' => (string) ((int) ($deal['probability'] ?? 0)) . '%'],
                ['label' => 'Days in stage', 'value' => (string) ((int) ($deal['days_in_stage'] ?? 0)) . ' d'],
            ],
            nextContact: (string) ($deal['next_contact_at'] ?? 'Not set'),
            attentionReason: (string) ($deal['attention_reason'] ?? 'No attention signal'),
            customer: (string) ($deal['customer'] ?? ''),
            phone: trim((string) ($deal['phone'] ?? '')) ?: '—',
            email: trim((string) ($deal['email'] ?? '')) ?: '—',
            telegram: trim((string) ($deal['telegram'] ?? '')) ?: '—',
            stages: $stages,
            owners: $owners,
            priority: (string) ($deal['priority'] ?? 'normal'),
            nextContactInput: $this->datetimeLocal($deal['next_contact_at'] ?? null),
            communications: $communications,
            approvals: $approvals,
            timeline: $timeline,
            intelligenceMetrics: [
                ['label' => 'Deal Health', 'value' => $this->display($intelligence['deal_health'] ?? null)],
                ['label' => 'Customer Intent', 'value' => $this->display($intelligence['customer_intent'] ?? null)],
                ['label' => 'Objections', 'value' => $this->display($intelligence['objections'] ?? null)],
                ['label' => 'Missing Information', 'value' => $this->display($intelligence['missing_information'] ?? null)],
                ['label' => 'Next Best Action', 'value' => $this->display($intelligence['next_best_action'] ?? null)],
                ['label' => 'Recommended Timing', 'value' => $this->display($intelligence['recommended_timing'] ?? null)],
                ['label' => 'Confidence', 'value' => $confidenceLabel],
            ],
            error: $error,
        );
    }

    /** @return array<string,mixed> */
    private function normalizeIntelligence(array $raw): array
    {
        $analysis = $this->array($raw['analysis'] ?? null);
        $decision = $this->array($raw['decision'] ?? null);
        $signals = $this->array($raw['signals'] ?? null);
        $actions = $this->list($raw['actions'] ?? null);
        $firstAction = $actions[0] ?? [];

        $pick = static function (mixed ...$values): mixed {
            foreach ($values as $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    return $value;
                }
            }

            return null;
        };

        return array_replace($raw, [
            'deal_health' => $pick($raw['deal_health'] ?? null, $analysis['deal_health'] ?? null, $decision['deal_health'] ?? null, $raw['health'] ?? null, $decision['risk_level'] ?? null),
            'customer_intent' => $pick($raw['customer_intent'] ?? null, $analysis['customer_intent'] ?? null, $signals['customer_intent'] ?? null, $decision['customer_intent'] ?? null),
            'objections' => $pick($raw['objections'] ?? null, $analysis['objections'] ?? null, $signals['objections'] ?? null),
            'missing_information' => $pick($raw['missing_information'] ?? null, $analysis['missing_information'] ?? null, $signals['missing_information'] ?? null, $raw['missing_info'] ?? null),
            'next_best_action' => $pick($raw['next_best_action'] ?? null, $analysis['next_best_action'] ?? null, $decision['next_best_action'] ?? null, $firstAction['reason'] ?? null, $firstAction['description'] ?? null, $firstAction['type'] ?? null),
            'recommended_timing' => $pick($raw['recommended_timing'] ?? null, $analysis['recommended_timing'] ?? null, $decision['recommended_timing'] ?? null, $firstAction['recommended_at'] ?? null, $firstAction['execute_at'] ?? null),
            'confidence' => $pick($raw['confidence'] ?? null, $analysis['confidence'] ?? null, $decision['confidence'] ?? null),
        ]);
    }

    private function timelineTone(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'approved', 'success' => 'positive',
            'failed', 'rejected', 'error' => 'danger',
            'pending', 'queued', 'warning' => 'warning',
            default => 'neutral',
        };
    }

    private function money(mixed $value, string $currency): string
    {
        return number_format((float) $value, 0, '.', ' ') . ' ' . $currency;
    }

    private function datetimeLocal(mixed $value): string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? '' : date('Y-m-d\\TH:i', $timestamp);
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                if (is_array($item)) {
                    $item = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $parts[] = is_string($key) ? $key . ': ' . (string) $item : (string) $item;
            }

            return $parts !== [] ? implode(' · ', $parts) : '—';
        }

        return (string) $value;
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
