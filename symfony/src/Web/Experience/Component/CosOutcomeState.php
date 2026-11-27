<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Presentation only: callers supply an already-authorized projected state.
 * Neither this component nor its tone determines business execution/verification.
 */
#[AsTwigComponent(name: 'CosOutcomeState', template: 'components/experience/cos_outcome_state.html.twig')]
final class CosOutcomeState
{
    private const TONES = [
        'loading' => 'info',
        'empty' => 'neutral',
        'disabled' => 'neutral',
        'error' => 'danger',
        'permission_denied' => 'danger',
        'queued' => 'neutral',
        'running' => 'info',
        'waiting_approval' => 'warning',
        'blocked' => 'danger',
        'completed_unverified' => 'warning',
        'completed_verified' => 'positive',
        'external_outcome_unknown' => 'warning',
        'reconciliation_required' => 'warning',
        'failed' => 'danger',
        'cancelled' => 'neutral',
        'stale_data' => 'warning',
    ];

    public string $state = 'external_outcome_unknown';

    /** Optional human-readable, already sanitized/authorized detail. */
    public ?string $detail = null;

    public function stateKey(): string
    {
        return isset(self::TONES[$this->state]) ? $this->state : 'external_outcome_unknown';
    }

    public function tone(): string
    {
        return self::TONES[$this->stateKey()];
    }
}
