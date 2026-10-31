<?php

namespace Ernestdefoe\LogoManager\Season;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Dates that move from year to year.
 *
 * A fixed "12-25" covers most of what a forum wants a seasonal logo for, but
 * not all of it — Easter walks across March and April, and American
 * Thanksgiving is a weekday rule, not a date. Those cannot be expressed as a
 * month/day range, so they get computed instead.
 *
 * 🚨 `easter_date()` is not used: it comes from ext-calendar, which is not in
 * Flarum's requirements and is absent from a good number of shared hosts. A
 * seasonal logo that throws a fatal on someone's host in April is a far worse
 * outcome than fifteen lines of arithmetic here.
 */
class Feasts
{
    public const ALL = [
        'easter',
        'thanksgiving_us',
        'thanksgiving_ca',
        'lunar_new_year',
        'mothers_day_us',
        'fathers_day_us',
    ];

    /**
     * Lunar New Year cannot be derived from the Gregorian calendar without
     * implementing the Chinese lunisolar calendar, so it is tabulated.
     *
     * 🚨 The table ends at 2040 and `null` is returned past it. A rule that
     * cannot resolve its date simply does not match — the logo stays as it
     * is, which is the right way for this to fail.
     */
    protected const LUNAR_NEW_YEAR = [
        2025 => '01-29', 2026 => '02-17', 2027 => '02-06', 2028 => '01-26',
        2029 => '02-13', 2030 => '02-03', 2031 => '01-23', 2032 => '02-11',
        2033 => '01-31', 2034 => '02-19', 2035 => '02-08', 2036 => '01-28',
        2037 => '02-15', 2038 => '02-04', 2039 => '01-24', 2040 => '02-12',
    ];

    public static function date(string $feast, int $year, DateTimeZone $tz): ?DateTimeImmutable
    {
        $day = match ($feast) {
            'easter' => self::easter($year),
            'thanksgiving_us' => self::nthWeekday($year, 11, 4, 4),      // 4th Thursday of November
            'thanksgiving_ca' => self::nthWeekday($year, 10, 1, 2),      // 2nd Monday of October
            'mothers_day_us' => self::nthWeekday($year, 5, 7, 2),        // 2nd Sunday of May
            'fathers_day_us' => self::nthWeekday($year, 6, 7, 3),        // 3rd Sunday of June
            'lunar_new_year' => self::LUNAR_NEW_YEAR[$year] ?? null,
            default => null,
        };

        if ($day === null) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "$year-$day 00:00:00", $tz) ?: null;
    }

    /**
     * Easter Sunday in the Gregorian calendar, by the anonymous Gregorian
     * algorithm (Meeus/Jones/Butcher). Returns "mm-dd".
     */
    protected static function easter(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%02d-%02d', $month, $day);
    }

    /**
     * The nth occurrence of a weekday in a month, as "mm-dd".
     *
     * $weekday is ISO-8601: 1 = Monday … 7 = Sunday.
     */
    protected static function nthWeekday(int $year, int $month, int $weekday, int $nth): string
    {
        $first = (int) date('N', mktime(0, 0, 0, $month, 1, $year));
        $offset = ($weekday - $first + 7) % 7;
        $day = 1 + $offset + ($nth - 1) * 7;

        return sprintf('%02d-%02d', $month, $day);
    }
}
