<?php
declare(strict_types=1);

namespace Sportlauf\Services;

use InvalidArgumentException;

final class TimeParser
{
    public static function parse(?string $input): ?int
    {
        $value = trim((string)$input);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '-')) {
            throw new InvalidArgumentException('Negative Zeiten sind ungueltig.');
        }

        if (preg_match('/^(\d{1,2}):([0-5]?\d)(?:[.,](\d))?$/', $value, $m)) {
            $minutes = (int)$m[1];
            $seconds = (int)$m[2];
            $tenths = isset($m[3]) ? (int)$m[3] : 0;
            return (($minutes * 60) + $seconds) * 10 + $tenths;
        }

        if (preg_match('/^\d+(?:[.,]\d)?$/', $value)) {
            $normalized = str_replace(',', '.', $value);
            return (int)round(((float)$normalized) * 10);
        }

        throw new InvalidArgumentException('Zeitformat ungueltig. Erlaubt sind z. B. 1:23.4, 1:23, 83.4 oder 83.');
    }

    public static function format(?int $tenths): string
    {
        if ($tenths === null) {
            return '';
        }

        $minutes = intdiv($tenths, 600);
        $remaining = $tenths % 600;
        $seconds = intdiv($remaining, 10);
        $decimal = $remaining % 10;

        return sprintf('%02d:%02d.%d', $minutes, $seconds, $decimal);
    }

    public static function best(?int $run1, ?int $run2): ?int
    {
        $times = array_values(array_filter([$run1, $run2], static fn (?int $time): bool => $time !== null));
        return $times === [] ? null : min($times);
    }
}
