<?php

namespace App\Http\Requests\Concerns;

/**
 * Reading and saving a price written by a provider, shared by the service price (ServiceRules)
 * and the extra price of an option (OptionRules).
 */
trait DecimalPrice
{
    /**
     * "25,50" is read as "25.50". The comma becomes a dot only when the whole price has the shape
     * "digits,digits" with at most $maxUnits digits before it, so "1.000,50" or "25,5,0" stay as
     * they are and are refused by the field's regex.
     */
    protected function commaToDot(string $field, int $maxUnits): void
    {
        $price = $this->input($field);

        if (is_string($price)) {
            $price = trim($price);

            if (preg_match('/^\d{1,'.$maxUnits.'},\d{1,2}$/', $price) === 1) {
                $price = str_replace(',', '.', $price);
            }

            $this->merge([$field => $price]);
        }
    }

    /**
     * "25" becomes "25.00", "25.5" becomes "25.50", "0025" becomes "25.00", "0.01" stays. No
     * floating point: only the digits are moved around.
     */
    private function normalizedPrice(string $price): string
    {
        [$units, $cents] = array_pad(explode('.', $price, 2), 2, '');
        $units = ltrim($units, '0');

        return ($units === '' ? '0' : $units).'.'.str_pad($cents, 2, '0');
    }
}
