<?php
declare(strict_types=1);

namespace Domains\Property\Model;

final class PropertyWorkflowPolicy
{
    private const STAGE_ORDER = [
        'intake', 'verification', 'pricing', 'media', 'ready_to_publish', 'published',
        'negotiation', 'reserved', 'deal', 'aftercare', 'archived',
    ];

    private const STAGE_RULES = [
        'intake' => ['label' => 'Приймання', 'requires_next_action' => true],
        'verification' => ['label' => 'Перевірка', 'requires_next_action' => true],
        'pricing' => ['label' => 'Оцінка і ціна', 'requires_next_action' => true],
        'media' => ['label' => 'Фото і матеріали', 'requires_next_action' => true],
        'ready_to_publish' => ['label' => 'Готовий до публікації', 'requires_next_action' => true],
        'published' => ['label' => 'Опублікований', 'requires_next_action' => true],
        'negotiation' => ['label' => 'Переговори', 'requires_next_action' => true],
        'reserved' => ['label' => 'Резерв', 'requires_next_action' => true],
        'deal' => ['label' => 'Угода', 'requires_next_action' => true],
        'aftercare' => ['label' => 'Післяпродажний супровід', 'requires_next_action' => false],
        'archived' => ['label' => 'Архів', 'requires_next_action' => false],
    ];

    public function stageRules(): array
    {
        return self::STAGE_RULES;
    }

    public function readiness(array $property, int $imageCount): array
    {
        $priceNote = mb_strtolower((string) (($property['short_description'] ?? '') . ' ' . ($property['description'] ?? '')));
        $checks = [
            ['key' => 'title', 'label' => 'назва', 'ok' => trim((string) ($property['title'] ?? '')) !== ''],
            ['key' => 'slug', 'label' => 'slug', 'ok' => trim((string) ($property['slug'] ?? '')) !== ''],
            ['key' => 'type', 'label' => 'тип обʼєкта', 'ok' => (int) ($property['type_id'] ?? 0) > 0],
            ['key' => 'location', 'label' => 'локація', 'ok' => (int) ($property['location_id'] ?? 0) > 0],
            ['key' => 'agent', 'label' => 'відповідальний агент', 'ok' => (int) ($property['agent_id'] ?? 0) > 0],
            ['key' => 'summary', 'label' => 'короткий опис', 'ok' => trim((string) ($property['short_description'] ?? '')) !== ''],
            ['key' => 'description', 'label' => 'повний опис', 'ok' => trim((string) ($property['description'] ?? '')) !== ''],
            ['key' => 'price', 'label' => 'ціна або примітка про ціну за запитом', 'ok' => $this->hasPriceOrRequestNote($property, $priceNote)],
            ['key' => 'area', 'label' => 'площа або ділянка', 'ok' => (float) ($property['area_total'] ?? 0) > 0 || (float) ($property['land_area'] ?? 0) > 0],
            ['key' => 'media', 'label' => 'хоча б одне фото', 'ok' => $imageCount > 0],
            ['key' => 'seo_title', 'label' => 'SEO title', 'ok' => trim((string) ($property['meta_title'] ?? '')) !== ''],
            ['key' => 'seo_description', 'label' => 'SEO description', 'ok' => trim((string) ($property['meta_description'] ?? '')) !== ''],
        ];

        $missing = [];
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $missing[] = $check['label'];
            }
        }

        return ['ready' => $missing === [], 'missing' => $missing, 'checks' => $checks];
    }

    public function requiresStatusNote(string $status): bool
    {
        return in_array($status, ['reserved', 'sold', 'archived'], true);
    }

    public function stage(string $stage): string
    {
        $stage = trim($stage);

        return array_key_exists($stage, self::STAGE_RULES) ? $stage : '';
    }

    public function stageLabel(string $stage): string
    {
        $stage = $this->stage($stage) ?: 'intake';

        return (string) (self::STAGE_RULES[$stage]['label'] ?? $stage);
    }

    public function nextStage(string $stage): string
    {
        $index = array_search($this->stage($stage), self::STAGE_ORDER, true);

        return $index === false ? '' : (string) (self::STAGE_ORDER[$index + 1] ?? '');
    }

    public function synchronizeStageWithStatus(array $property): array
    {
        $status = (string) ($property['status'] ?? 'draft');
        $stage = $this->stage((string) ($property['operational_stage'] ?? 'intake')) ?: 'intake';

        if ($status === 'archived') {
            $property['operational_stage'] = 'archived';
        } elseif ($status === 'sold') {
            if (!in_array($stage, ['deal', 'aftercare'], true)) {
                $property['operational_stage'] = 'deal';
            }
        } elseif ($status === 'reserved') {
            $property['operational_stage'] = 'reserved';
        } elseif ($this->isPublicStatus($status) && $this->stageRank($stage) < $this->stageRank('published')) {
            $property['operational_stage'] = 'published';
        }

        return $property;
    }

    public function stageIssues(array $property, int $imageCount): array
    {
        $stage = $this->stage((string) ($property['operational_stage'] ?? ''));
        if ($stage === '') {
            return ['невідомий етап роботи'];
        }

        $issues = [];
        $rank = $this->stageRank($stage);
        $status = (string) ($property['status'] ?? 'draft');
        $statusNote = trim((string) ($property['status_note'] ?? ''));
        $priceNote = mb_strtolower((string) (($property['short_description'] ?? '') . ' ' . ($property['description'] ?? '')));

        if (!empty(self::STAGE_RULES[$stage]['requires_next_action'])) {
            if (trim((string) ($property['next_action_title'] ?? '')) === '') {
                $issues[] = 'вкажіть наступну дію';
            }
            if (trim((string) ($property['next_action_due_at'] ?? '')) === '') {
                $issues[] = 'вкажіть дедлайн наступної дії';
            }
        }

        if ($rank >= $this->stageRank('verification')) {
            if ((int) ($property['agent_id'] ?? 0) <= 0) {
                $issues[] = 'призначте відповідального';
            }
            if (trim((string) ($property['address'] ?? '')) === '' && (int) ($property['property_group_id'] ?? 0) <= 0) {
                $issues[] = 'вкажіть адресу або групу обʼєктів';
            }
        }

        if ($rank >= $this->stageRank('pricing')) {
            if (!$this->hasPriceOrRequestNote($property, $priceNote)) {
                $issues[] = 'вкажіть ціну або примітку про ціну за запитом';
            }
            if ((float) ($property['area_total'] ?? 0) <= 0 && (float) ($property['land_area'] ?? 0) <= 0) {
                $issues[] = 'вкажіть площу або ділянку';
            }
        }

        if (in_array($stage, ['ready_to_publish', 'published', 'negotiation'], true)) {
            $readiness = $this->readiness($property, $imageCount);
            if (!$readiness['ready']) {
                $issues[] = 'не пройдено чекліст публікації: ' . implode(', ', $readiness['missing']);
            }
        }
        if (in_array($stage, ['published', 'negotiation'], true) && !in_array($status, ['published', 'active', 'reserved', 'sold'], true)) {
            $issues[] = 'спершу опублікуйте обʼєкт';
        }
        if ($stage === 'reserved' && $status !== 'reserved') {
            $issues[] = 'для етапу резерву потрібен статус "резерв"';
        }
        if (in_array($stage, ['deal', 'aftercare'], true) && $status !== 'sold') {
            $issues[] = 'для цього етапу потрібен статус "продано"';
        }
        if ($stage === 'archived' && $status !== 'archived') {
            $issues[] = 'для архівного етапу потрібен статус "архів"';
        }
        if ($this->requiresStatusNote($status) && $statusNote === '') {
            $issues[] = 'додайте причину або контекст статусу';
        }

        return array_values(array_unique($issues));
    }

    public function matchesQuality(array $property, string $quality): bool
    {
        return match ($quality) {
            'ready' => !empty($property['is_ready_to_publish']),
            'not_ready' => empty($property['is_ready_to_publish']),
            'no_agent' => (int) ($property['agent_id'] ?? 0) <= 0,
            'no_photo' => (int) ($property['image_count'] ?? 0) <= 0,
            'no_price' => ($property['price_amount'] ?? null) === null || (float) ($property['price_amount'] ?? 0) <= 0,
            'no_commission' => !in_array((string) ($property['commission_type'] ?? 'none'), ['percent', 'fixed', 'included'], true),
            'needs_status_note' => $this->requiresStatusNote((string) ($property['status'] ?? ''))
                && trim((string) ($property['status_note'] ?? '')) === '',
            'overdue_action' => $this->isActionOverdue($property['next_action_due_at'] ?? null),
            'no_next_action' => trim((string) ($property['next_action_title'] ?? '')) === '',
            'stage_blocked' => !empty($property['operational_stage_issues']),
            default => true,
        };
    }

    public function qualityIssues(array $property): array
    {
        $issues = [];
        if (empty($property['is_ready_to_publish'])) $issues[] = 'не готовий: ' . (int) ($property['readiness_missing_count'] ?? 0);
        if ($this->matchesQuality($property, 'no_agent')) $issues[] = 'без відповідального';
        if ($this->matchesQuality($property, 'no_photo')) $issues[] = 'без фото';
        if ($this->matchesQuality($property, 'no_price')) $issues[] = 'без ціни';
        if ($this->matchesQuality($property, 'no_commission')) $issues[] = 'без комісії';
        if ($this->matchesQuality($property, 'needs_status_note')) $issues[] = 'без причини статусу';
        if ($this->matchesQuality($property, 'overdue_action')) $issues[] = 'прострочена дія';
        if ($this->matchesQuality($property, 'no_next_action')) $issues[] = 'немає наступної дії';
        foreach (($property['operational_stage_issues'] ?? []) as $issue) $issues[] = 'етап: ' . $issue;

        return $issues;
    }

    public function statuses(): array
    {
        return ['draft', 'submitted', 'moderation', 'published', 'active', 'hidden', 'reserved', 'sold', 'archived', 'needs_update'];
    }

    public function isPublicStatus(string $status): bool
    {
        return in_array($status, ['published', 'active'], true);
    }

    public function visibilityOptions(): array
    {
        return ['public', 'team', 'partners', 'private'];
    }

    public function salePriorities(): array
    {
        return ['low', 'normal', 'high', 'urgent'];
    }

    public function commissionTypes(): array
    {
        return ['none', 'percent', 'fixed', 'included'];
    }

    private function stageRank(string $stage): int
    {
        $stage = $this->stage($stage) ?: 'intake';
        $index = array_search($stage, self::STAGE_ORDER, true);

        return $index === false ? 0 : (int) $index;
    }

    private function hasPriceOrRequestNote(array $property, string $priceNote): bool
    {
        return (float) ($property['price_amount'] ?? 0) > 0
            || (str_contains($priceNote, 'ціна') && (str_contains($priceNote, 'запит') || str_contains($priceNote, 'уточ')));
    }

    private function isActionOverdue(mixed $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') return false;
        $timestamp = strtotime($value);

        return $timestamp !== false && $timestamp < time();
    }
}
