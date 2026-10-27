<?php

namespace VitisStudio\Tendril\Marketing\Money;

use InvalidArgumentException;

/**
 * Revenue is stored and summed in integer minor units with a currency, so
 * rollups never accumulate float drift.
 */
final class Money
{
    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);
        $map = (array) config('tendril-marketing.currency_exponents', []);

        return isset($map[$currency]) && is_numeric($map[$currency]) ? (int) $map[$currency] : 2;
    }

    public static function normalizeCurrency(mixed $currency): ?string
    {
        if (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            return null;
        }

        return strtoupper($currency);
    }

    /**
     * Convert a major-unit amount ("12.34", 12.34) to minor units (1234).
     * Numeric strings are converted exactly; floats are rounded half-up.
     */
    public static function toMinor(int|float|string $amount, string $currency): int
    {
        $exponent = self::exponent($currency);

        if (is_string($amount)) {
            $amount = trim($amount);

            if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
                throw new InvalidArgumentException("Invalid amount [{$amount}].");
            }

            $fraction = $m[3] ?? '';
            $kept = substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
            $minor = (int) ($m[2].$kept);
            $next = strlen($fraction) > $exponent ? (int) substr($fraction, $exponent, 1) : 0;

            if ($next >= 5) {
                $minor++;
            }

            return $m[1] === '-' ? -$minor : $minor;
        }

        return (int) round($amount * (10 ** $exponent), 0, PHP_ROUND_HALF_UP);
    }

    public static function format(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);

        return number_format($minor / (10 ** $exponent), $exponent, '.', ',').' '.strtoupper($currency);
    }

    /**
     * Split an integer amount by weights with the largest-remainder method so
     * the parts always sum exactly to the total.
     *
     * @param  array<array-key, float>  $weights
     * @return array<array-key, int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);

        if ($weights === [] || $sum <= 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $key => $weight) {
            $exact = $total * ($weight / $sum);
            $parts[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $parts[$key];
            $allocated += $parts[$key];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($allocated >= $total) {
                break;
            }

            $parts[$key]++;
            $allocated++;
        }

        return $parts;
    }
}
