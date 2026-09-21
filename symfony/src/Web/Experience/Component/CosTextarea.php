<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosTextarea',
    template: 'components/experience/cos_textarea.html.twig',
)]
final class CosTextarea
{
    public ?string $id = null;
    public string $name = '';
    public string $label = '';
    public string $value = '';
    public ?string $placeholder = null;
    public ?string $help = null;
    public ?string $autocomplete = null;
    public ?string $inputmode = null;
    public int $rows = 4;
    public ?int $maxlength = null;
    public bool $disabled = false;
    public bool $required = false;

    /** @var list<string> */
    public array $errors = [];

    public function fieldId(): string
    {
        if ($this->id !== null && $this->id !== '') {
            return $this->id;
        }

        return 'cos-field-' . trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $this->name), '-');
    }

    public function describedBy(): string
    {
        $ids = [];

        if ($this->help !== null && $this->help !== '') {
            $ids[] = $this->fieldId() . '-help';
        }

        if ($this->errors !== []) {
            $ids[] = $this->fieldId() . '-errors';
        }

        return implode(' ', $ids);
    }
}
