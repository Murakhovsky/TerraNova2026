<?php

declare(strict_types=1);

namespace App\Web\Experience\Form;

enum FormDraftPolicy: string
{
    case None = 'none';
    case Manual = 'manual';
    case Autosave = 'autosave';
}
