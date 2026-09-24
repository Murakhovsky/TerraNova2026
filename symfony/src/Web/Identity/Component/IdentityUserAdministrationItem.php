<?php

declare(strict_types=1);

namespace App\Web\Identity\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'IdentityUserAdministrationItem',
    template: 'components/identity/user_administration_item.html.twig',
)]
final class IdentityUserAdministrationItem
{
    /** @var array<string,mixed> */
    public array $user = [];

    /** @var array<string,string> */
    public array $roleOptions = [];

    /** @var array<string,string> */
    public array $statusOptions = [];

    public string $csrfToken = '';
}
