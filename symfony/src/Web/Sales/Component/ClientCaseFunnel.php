<?php
declare(strict_types=1);

namespace App\Web\Sales\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name:'ClientCaseFunnel',template:'components/client_case/client_case_funnel.html.twig')]
final class ClientCaseFunnel
{
    public array $stages=[];
}
