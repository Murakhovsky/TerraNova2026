<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use InvalidArgumentException;

final readonly class UIAction
{
    /**
     * @param list<string> $placements
     */
    public function __construct(
        public string $id,
        public string $label,
        public UIActionIntent $intent,
        public ?string $icon = null,
        public ?string $permission = null,
        public bool $enabled = true,
        public ?string $disabledReason = null,
        public UIActionConfirmation|string|null $confirmation = null,
        public ?string $command = null,
        public bool $async = false,
        public int $dangerLevel = 0,
        public int $priority = 100,
        public array $placements = [UIActionPlacement::WORKSPACE],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/', $this->id)) {
            throw new InvalidArgumentException('UIAction id must be a stable namespaced identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('UIAction label must not be empty.');
        }

        if ($this->permission !== null && trim($this->permission) === '') {
            throw new InvalidArgumentException('UIAction permission must be null or a non-empty identifier.');
        }

        if ($this->intent === UIActionIntent::Execute && ($this->command === null || trim($this->command) === '')) {
            throw new InvalidArgumentException('Executable UIAction requires an Application Command identifier.');
        }

        $danger = UIActionDangerLevel::tryFrom($this->dangerLevel);
        if ($danger === null) {
            throw new InvalidArgumentException('UIAction dangerLevel must be between 0 and 3.');
        }

        if (!$this->enabled && ($this->disabledReason === null || trim($this->disabledReason) === '')) {
            throw new InvalidArgumentException('Disabled UIAction must explain why it is disabled.');
        }

        if ($this->isDangerous() && $this->confirmationContract() === null) {
            throw new InvalidArgumentException('Dangerous UIAction requires an explicit confirmation contract.');
        }

        if ($danger->requiresStepUp() && !$this->confirmationContract()?->stepUp) {
            throw new InvalidArgumentException('Critical UIAction requires step-up confirmation.');
        }

        if ($this->placements === []) {
            throw new InvalidArgumentException('UIAction requires at least one placement.');
        }

        foreach ($this->placements as $placement) {
            UIActionPlacement::assert($placement);
        }

        if (count($this->placements) !== count(array_unique($this->placements))) {
            throw new InvalidArgumentException('UIAction placements must be unique.');
        }
    }

    public function danger(): UIActionDangerLevel
    {
        return UIActionDangerLevel::from($this->dangerLevel);
    }

    public function isDangerous(): bool
    {
        return $this->dangerLevel > 0
            || $this->intent === UIActionIntent::Delete
            || $this->intent === UIActionIntent::Danger;
    }

    public function confirmationContract(): ?UIActionConfirmation
    {
        if ($this->confirmation instanceof UIActionConfirmation) {
            return $this->confirmation;
        }

        if (is_string($this->confirmation) && trim($this->confirmation) !== '') {
            return UIActionConfirmation::simple($this->confirmation);
        }

        return null;
    }

    public function supportsPlacement(string $placement): bool
    {
        UIActionPlacement::assert($placement);

        return in_array($placement, $this->placements, true);
    }

    public function withAvailability(bool $enabled, ?string $reason = null): self
    {
        if ($enabled === $this->enabled && ($enabled || $reason === $this->disabledReason)) {
            return $this;
        }

        return new self(
            id: $this->id,
            label: $this->label,
            intent: $this->intent,
            icon: $this->icon,
            permission: $this->permission,
            enabled: $enabled,
            disabledReason: $enabled ? null : ($reason ?: $this->disabledReason ?: 'Action is unavailable.'),
            confirmation: $this->confirmation,
            command: $this->command,
            async: $this->async,
            dangerLevel: $this->dangerLevel,
            priority: $this->priority,
            placements: $this->placements,
        );
    }
}
