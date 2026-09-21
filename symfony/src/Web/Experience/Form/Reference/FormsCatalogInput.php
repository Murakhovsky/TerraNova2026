<?php

declare(strict_types=1);

namespace App\Web\Experience\Form\Reference;

use App\Web\Experience\Form\FormInputDto;
use Symfony\Component\Validator\Constraints as Assert;

final class FormsCatalogInput implements FormInputDto
{
    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 160)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Enter an email address.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    #[Assert\Length(max: 190)]
    public string $email = '';

    #[Assert\Choice(choices: ['normal', 'high', 'urgent'])]
    public string $priority = 'normal';

    #[Assert\Length(max: 1200)]
    public string $notes = '';
}
