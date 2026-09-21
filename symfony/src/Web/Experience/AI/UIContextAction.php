<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Action\UIActionConfirmation;

final readonly class UIContextAction
{
    public function __construct(
        public string $id,
        public string $intent,
        public bool $enabled,
        public ?string $resourceId,
        public ?string $command,
        public int $dangerLevel,
        public ?UIActionConfirmation $confirmation,
        public ?string $disabledReason,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'intent' => $this->intent,
            'enabled' => $this->enabled,
            'resource_id' => $this->resourceId,
            'command' => $this->command,
            'danger_level' => $this->dangerLevel,
            'confirmation' => $this->confirmation === null ? null : [
                'required' => true,
                'step_up' => $this->confirmation->stepUp,
            ],
            'disabled_reason' => $this->disabledReason,
        ];
    }
}
