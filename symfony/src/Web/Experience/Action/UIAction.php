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
        public ?string $confirmation = null,
        public ?string $command = null,
        public bool $async = false,
        public int $dangerLevel = 0,
        public int $priority = 100,
        public array $placements = ['workspace'],
    ) {
        if (!preg_match('/^[a-z][a-z0-9._-]*$/', $this->id)) {
            throw new InvalidArgumentException('UIAction id must be a stable namespaced identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('UIAction label must not be empty.');
        }

        if ($this->dangerLevel < 0 || $this->dangerLevel > 3) {
            throw new InvalidArgumentException('UIAction dangerLevel must be between 0 and 3.');
        }

        if (!$this->enabled && ($this->disabledReason === null || trim($this->disabledReason) === '')) {
            throw new InvalidArgumentException('Disabled UIAction must explain why it is disabled.');
        }

        if ($this->isDangerous() && ($this->confirmation === null || trim($this->confirmation) === '')) {
            throw new InvalidArgumentException('Dangerous UIAction requires an explicit confirmation contract.');
        }

        if ($this->placements === []) {
            throw new InvalidArgumentException('UIAction requires at least one placement.');
        }

        foreach ($this->placements as $placement) {
            if (!preg_match('/^[a-z][a-z0-9._-]*$/', $placement)) {
                throw new InvalidArgumentException('UIAction placement must be a stable lowercase identifier.');
            }
        }

        if (count($this->placements) !== count(array_unique($this->placements))) {
            throw new InvalidArgumentException('UIAction placements must be unique.');
        }
    }

    public function isDangerous(): bool
    {
        return $this->dangerLevel > 0
            || $this->intent === UIActionIntent::Delete
            || $this->intent === UIActionIntent::Danger;
    }
}
