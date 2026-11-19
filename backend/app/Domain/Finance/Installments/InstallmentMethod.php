<?php

declare(strict_types=1);

namespace App\Domain\Finance\Installments;

enum InstallmentMethod: string
{
    case Zero = 'zero';           // 0% installment
    case Flat = 'flat';           // interest on the original principal for the whole term
    case Reducing = 'reducing';   // rate given is the NOMINAL annual rate, charged on remaining balance
    case Effective = 'effective'; // rate given is the EFFECTIVE annual rate (EIR), converted to a periodic rate
}