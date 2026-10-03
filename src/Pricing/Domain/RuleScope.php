<?php

declare(strict_types=1);

namespace App\Pricing\Domain;

enum RuleScope: string
{
    case Item = 'item';
    case Cart = 'cart';
}
