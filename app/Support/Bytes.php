<?php

namespace App\Support;

/**
 * Human-readable byte sizes, binary units (1 KB = 1024 B) as the OS file
 * managers on the server and the Storage page agree on: "0 B", "1023 B",
 * "1.0 KB", "1.5 GB", "2.3 TB". Bytes are whole numbers; larger units get
 * one decimal. A value that would round up to 1024 of a unit is shown in
 * the next unit ("1.0 MB", never "1024.0 KB"). TB is the largest unit.
 */
final class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function human(int|float|null $bytes, int $decimals = 1): string
    {
        $value = max(0.0, (float) ($bytes ?? 0));
        if ($value < 1024) {
            return ((int) $value) . ' B';
        }

        $unit = 0;
        $last = count(self::UNITS) - 1;
        while ($unit < $last && round($value, $decimals) >= 1024) {
            $value /= 1024;
            $unit++;
        }

        // Only TB can pass 1024 of a unit: a thousands separator there alone.
        return number_format($value, $decimals, '.', $unit === $last ? ',' : '') . ' ' . self::UNITS[$unit];
    }
}
