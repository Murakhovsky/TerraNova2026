<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;

final class PropertyMatchLifecycle
{
    public static function allows(?string $from,string $to):bool
    {
        if ($from===null) return in_array($to,['suggested','interested'],true);
        if ($from===$to) return in_array($from,['suggested','sent','interested','viewing','rejected','deal'],true);
        return in_array($to,match($from){
            'suggested'=>['sent','interested','rejected'],
            'sent'=>['interested','viewing','rejected'],
            'interested'=>['viewing','rejected','deal'],
            'viewing'=>['interested','rejected','deal'],
            default=>[],
        },true);
    }
}
