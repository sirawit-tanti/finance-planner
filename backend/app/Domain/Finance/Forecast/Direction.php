<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

enum Direction: string
{
    case In = 'in';           // money arrives in an account
    case Out = 'out';         // money leaves an account
    case Reserve = 'reserve'; // money set aside: stays in the account, but is not free to spend

    /** Order within one day: money in first, then out, then reserve. */
    public function order(): int
    {
        return match ($this) {
            self::In => 0,
            self::Out => 1,
            self::Reserve => 2,
        };
    }
}