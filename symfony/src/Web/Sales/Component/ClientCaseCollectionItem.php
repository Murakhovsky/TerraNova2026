<?php
declare(strict_types=1);

namespace App\Web\Sales\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name:'ClientCaseCollectionItem',template:'components/client_case/client_case_collection_item.html.twig')]
final class ClientCaseCollectionItem
{
    public array $item=[];
    public array $stageOptions=[];
    public array $managerOptions=[];
    public array $statusOptions=[];
    public array $priorityOptions=[];
    public string $csrfToken='';
}
