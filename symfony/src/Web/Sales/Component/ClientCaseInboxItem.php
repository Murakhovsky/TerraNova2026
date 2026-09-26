<?php

declare(strict_types=1);

namespace App\Web\Sales\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'ClientCaseInboxItem', template: 'components/client_case/client_case_inbox_item.html.twig')]
final class ClientCaseInboxItem
{
    /** @var array<string,mixed> */
    public array $item = [];

    /** @var array<string,string> */
    public array $statusOptions = [];

    /** @var array<string,string> */
    public array $activityOptions = [];

    /** @var array<string,string> */
    public array $priorityOptions = [];

    /** @var list<array{id:int,label:string}> */
    public array $managerOptions = [];

    /** @var list<array{id:int,label:string}> */
    public array $openCaseOptions = [];

    public string $csrfToken = '';
    public string $returnUrl = '';
}
