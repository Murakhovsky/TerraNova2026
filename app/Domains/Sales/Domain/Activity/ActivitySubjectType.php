<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Activity;

enum ActivitySubjectType: string
{
    case Lead = 'lead';
    case Contact = 'contact';
    case Company = 'company';
    case Opportunity = 'opportunity';
}
