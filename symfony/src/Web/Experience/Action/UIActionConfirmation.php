<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use InvalidArgumentException;

final readonly class UIActionConfirmation
{
    public function __construct(
        public string $message,
        public string $confirmLabel = 'Confirm',
        public string $cancelLabel = 'Cancel',
        public bool $stepUp = false,
    ) {
        if (trim($this->message) === '') {
            throw new InvalidArgumentException('UIAction confirmation message must not be empty.');
        }

        if (trim($this->confirmLabel) === '' || trim($this->cancelLabel) === '') {
            throw new InvalidArgumentException('UIAction confirmation labels must not be empty.');
        }
    }

    public static function simple(string $message, string $confirmLabel = 'Confirm'): self
    {
        return new self($message, $confirmLabel);
    }

    public static function stepUp(string $message, string $confirmLabel = 'Confirm'): self
    {
        return new self($message, $confirmLabel, 'Cancel', true);
    }
}
