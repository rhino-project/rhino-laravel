<?php

namespace Rhino\Traits;

use App\Enums\CurrencyOption;
use Exception;

trait ViewModelHelpers
{
    /**
     * @throws Exception
     */
    public function formatPrice(float $price, CurrencyOption $currencyType): string
    {
        return match ($currencyType) {
            CurrencyOption::USD => '$' . number_format($price, 2),
            CurrencyOption::CAD => 'C$' . number_format($price, 2),
            CurrencyOption::BRL => 'R$' . number_format($price, 2, ',', '.'),
            CurrencyOption::EUR => '€' . number_format($price, 2, ',', '.'),
            CurrencyOption::CHF => 'CHF' . number_format($price, 2),
            CurrencyOption::GBP => '£' . number_format($price, 2),
            default => throw new Exception('Invalid currency type'),
        };
    }
}
