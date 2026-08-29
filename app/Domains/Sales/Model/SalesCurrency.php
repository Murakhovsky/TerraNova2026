<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum SalesCurrency: string
{
    use HasStringValues;

    case Usd = 'USD';
    case Eur = 'EUR';
    case Uah = 'UAH';
}
