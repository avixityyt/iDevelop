<?php
declare(strict_types=1);

// Builds the CSV: UTF-8 without BOM, CRLF line endings.
final class CsvBuilder
{
    /** @param array<int, array{time:string,node:int,type:string,celsius:float,delta:?float}> $rows */
    public static function build(array $rows): string
    {
        $out = "Time,Node,Type,Temperature°C,Δ\r\n";
        foreach ($rows as $r) {
            $out .= implode(',', [
                $r['time'],
                $r['node'],
                self::field($r['type']),
                self::number($r['celsius']),
                $r['delta'] === null ? '' : self::number($r['delta']),
            ]) . "\r\n";
        }
        return $out;
    }

    /** At most 2 decimals, no trailing zeros (40.50 -> 40.5, 40.00 -> 40). */
    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format(round($value, 2), 2, '.', ''), '0'), '.');
        // Rounding a tiny negative leaves "-0".
        return ($text === '-0' || $text === '') ? '0' : $text;
    }

    private static function field(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"' . str_replace('"', '""', $value) . '"' : $value;
    }
}
