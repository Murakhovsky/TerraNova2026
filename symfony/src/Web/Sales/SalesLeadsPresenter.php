<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesLeadsViewModel;

final class SalesLeadsPresenter
{
    /**
     * @param array<string,mixed> $data
     * @param array{q:string,status:string,source:string} $filters
     * @param list<array<string,mixed>> $owners
     */
    public function present(
        array $data,
        array $filters,
        array $owners,
        ?string $error = null,
    ): SalesLeadsViewModel {
        $items = [];

        foreach ($this->list($data['items'] ?? null) as $lead) {
            $id = (int) ($lead['id'] ?? 0);
            $status = strtolower(trim((string) ($lead['status'] ?? 'new')));
            $phone = trim((string) ($lead['phone'] ?? ''));
            $email = trim((string) ($lead['email'] ?? ''));
            $contact = $phone !== '' ? $phone : ($email !== '' ? $email : 'без контакту');
            $dealId = (int) ($lead['deal_id'] ?? 0);

            $items[] = [
                'id' => $id,
                'name' => (string) ($lead['name'] ?? ('Lead #' . $id)),
                'source' => (string) ($lead['source'] ?? 'Direct'),
                'contact' => $contact,
                'ownerId' => (int) ($lead['owner_id'] ?? 0),
                'ownerName' => (string) ($lead['owner_name'] ?? 'Unassigned'),
                'status' => $status !== '' ? $status : 'new',
                'statusTone' => match ($status) {
                    'qualified' => 'positive',
                    'disqualified' => 'danger',
                    default => 'neutral',
                },
                'dealId' => $dealId > 0 ? $dealId : null,
            ];
        }

        $ownerOptions = [];
        foreach ($owners as $owner) {
            $id = (int) ($owner['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $label = trim((string) ($owner['full_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($owner['email'] ?? ''));
            }

            $ownerOptions[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : ('#' . $id),
            ];
        }

        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];

        return new SalesLeadsViewModel(
            items: $items,
            owners: $ownerOptions,
            filters: $filters,
            page: max(1, (int) ($pagination['page'] ?? 1)),
            perPage: max(1, (int) ($pagination['per_page'] ?? 50)),
            hasMore: (bool) ($pagination['has_more'] ?? false),
            error: $error,
        );
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
