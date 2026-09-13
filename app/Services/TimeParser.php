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
            throw new InvalidArgumentException('Negative Zeiten sind ungültig.');
        }

        if (preg_match('/^(\d{1,2}):([0-5]?\d)(?:[.,](\d{1,2}))?$/', $value, $m)) {
            $minutes = (int)$m[1];
            $seconds = (int)$m[2];
            $hundredths = (int)str_pad($m[3] ?? '', 2, '0');
            return (($minutes * 60) + $seconds) * 100 + $hundredths;
        }

        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $value, $m)) {
            return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        }

        throw new InvalidArgumentException('Zeitformat ungültig. Erlaubt sind z. B. 1:23.45, 1:23, 83.45 oder 83 (Punkt oder Komma, maximal zwei Nachkommastellen).');
    }

    public static function format(?int $hundredths): string
    {
        if ($hundredths === null) {
            return '';
        }

        $minutes = intdiv($hundredths, 6000);
        $remaining = $hundredths % 6000;
        $seconds = intdiv($remaining, 100);
        $decimal = $remaining % 100;

        return sprintf('%02d:%02d.%02d', $minutes, $seconds, $decimal);
    }

    public static function best(?int $run1, ?int $run2): ?int
    {
        $times = array_values(array_filter([$run1, $run2], static fn (?int $time): bool => $time !== null));
        return $times === [] ? null : min($times);
    }
}
