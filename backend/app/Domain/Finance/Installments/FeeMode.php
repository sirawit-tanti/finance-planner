<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

enum FeeMode: string
{
    case None = 'none';
    case Upfront = 'upfront'; // paid once at the start date (installment no. 0)
    case Spread = 'spread';   // divided across every installment
}