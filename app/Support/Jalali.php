<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use IntlCalendar;
use IntlDateFormatter;

/**
 * Month and day bucketing for charts. Persian locales get the Jalali calendar
 * (months start on the 1st of the Jalali month); anything else falls back to
 * the Gregorian calendar. Like Format, it needs only ext-intl.
 */
final class Jalali
{
    private const LOCALE = 'fa_IR@calendar=persian';

    /**
     * The first instant of each of the last $count months, the current one
     * last, in the given timezone.
     *
     * @return list<CarbonImmutable>
     */
    public static function monthStarts(int $count, string $timezone, ?CarbonInterface $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->toImmutable()->setTimezone($timezone);

        if (! self::usesJalali()) {
            return array_map(
                fn (int $ago): CarbonImmutable => $now->startOfMonth()->subMonthsNoOverflow($ago),
                range($count - 1, 0),
            );
        }

        $starts = [];

        foreach (range($count - 1, 0) as $ago) {
            $calendar = IntlCalendar::createInstance($timezone, self::LOCALE);
            $calendar->setTime($now->getTimestamp() * 1000);
            $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
            $calendar->add(IntlCalendar::FIELD_MONTH, -$ago);

            $starts[] = CarbonImmutable::instance($calendar->toDateTime())->setTimezone($timezone)->startOfDay();
        }

        return $starts;
    }

    /**
     * Identifies the month a moment falls in, for grouping.
     */
    public static function monthKey(CarbonInterface $moment, string $timezone): string
    {
        return self::format($moment, $timezone, 'yyyy/MM', 'Y/m');
    }

    public static function monthLabel(CarbonInterface $moment, string $timezone): string
    {
        return self::format($moment, $timezone, 'LLLL', 'M');
    }

    public static function dayLabel(CarbonInterface $moment, string $timezone): string
    {
        return self::format($moment, $timezone, 'MM/dd', 'm/d');
    }

    private static function usesJalali(): bool
    {
        return app()->getLocale() === 'fa' && class_exists(IntlDateFormatter::class);
    }

    private static function format(CarbonInterface $moment, string $timezone, string $icuPattern, string $phpFormat): string
    {
        if (! self::usesJalali()) {
            return $moment->copy()->setTimezone($timezone)->format($phpFormat);
        }

        return (string) (new IntlDateFormatter(
            self::LOCALE,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
            IntlDateFormatter::TRADITIONAL,
            $icuPattern,
        ))->format($moment->getTimestamp());
    }
}
