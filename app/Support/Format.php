<?php

namespace App\Support;

use Carbon\CarbonInterface;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Locale-aware presentation helpers. Persian locales get the Jalali calendar
 * and Persian digits; anything else falls back to plain ISO-like output.
 */
final class Format
{
    public static function dateTime(?CarbonInterface $value): string
    {
        if ($value === null) {
            return __('ui.common.none');
        }

        $local = $value->copy()->setTimezone(config('jocko.display_timezone'));

        if (app()->getLocale() !== 'fa' || ! class_exists(IntlDateFormatter::class)) {
            return $local->format('Y-m-d H:i');
        }

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $local->getTimezone(),
            IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/dd HH:mm',
        );

        return (string) $formatter->format($local->getTimestamp());
    }

    /**
     * A calendar date with no time of day. It is not shifted between
     * timezones: a payment made "on the 5th" stays on the 5th.
     */
    public static function date(?CarbonInterface $value): string
    {
        if ($value === null) {
            return __('ui.common.none');
        }

        $day = $value->copy()->utc();

        if (app()->getLocale() !== 'fa' || ! class_exists(IntlDateFormatter::class)) {
            return $day->format('Y-m-d');
        }

        $formatter = new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::TRADITIONAL,
            'yyyy/MM/dd',
        );

        return (string) $formatter->format($day->getTimestamp());
    }

    public static function ago(?CarbonInterface $value): string
    {
        if ($value === null) {
            return __('ui.common.never');
        }

        return $value->copy()->locale(app()->getLocale())->diffForHumans();
    }

    /**
     * A length of time as the largest whole unit: "5 min", "3 h", "2 d".
     */
    public static function duration(int|float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        [$count, $unit] = match (true) {
            $seconds >= 86400 => [intdiv($seconds, 86400), 'day'],
            $seconds >= 3600 => [intdiv($seconds, 3600), 'hour'],
            $seconds >= 60 => [intdiv($seconds, 60), 'minute'],
            default => [$seconds, 'second'],
        };

        return __('ui.duration.'.$unit, ['count' => self::number($count)]);
    }

    public static function number(int|float $value): string
    {
        if (app()->getLocale() !== 'fa' || ! class_exists(NumberFormatter::class)) {
            return number_format($value);
        }

        return (string) (new NumberFormatter('fa_IR', NumberFormatter::DECIMAL))->format($value);
    }

    /**
     * Pretty-print JSON for display, truncated to a safe size.
     *
     * @return array{text: string, truncated: bool}
     */
    public static function json(mixed $value, int $limit = 20000): array
    {
        $text = (string) json_encode($value ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strlen($text) > $limit
            ? ['text' => mb_strcut($text, 0, $limit), 'truncated' => true]
            : ['text' => $text, 'truncated' => false];
    }
}
