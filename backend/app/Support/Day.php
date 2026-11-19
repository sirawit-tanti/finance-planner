<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Calendar days as UTC midnight, so no timezone or time of day can shift a date. */
final class Day
{
    public static function parse(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public static function of(DateTimeInterface $date): DateTimeImmutable
    {
        return self::parse($date->format('Y-m-d'));
    }

    /** Today's date in Thailand. Uses Carbon so tests can freeze it with Carbon::setTestNow(). */
    public static function today(): DateTimeImmutable
    {
        return self::parse(Carbon::now('Asia/Bangkok')->format('Y-m-d'));
    }
}