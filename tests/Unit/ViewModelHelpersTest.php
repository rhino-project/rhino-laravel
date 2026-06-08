<?php

namespace App\Enums;

// The trait references App\Enums\CurrencyOption, which lives in the host app.
// Define a stand-in with the same cases (plus one extra to exercise the
// "invalid currency" default branch).
enum CurrencyOption
{
    case USD;
    case CAD;
    case BRL;
    case EUR;
    case CHF;
    case GBP;
    case JPY; // not handled by formatPrice → exercises the default/throw branch
}

namespace Rhino\Tests\Unit;

use App\Enums\CurrencyOption;
use Exception;
use Rhino\Tests\TestCase;
use Rhino\Traits\ViewModelHelpers;

class CurrencyFormatter
{
    use ViewModelHelpers;
}

class ViewModelHelpersTest extends TestCase
{
    private CurrencyFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new CurrencyFormatter();
    }

    public function test_formats_each_supported_currency(): void
    {
        $this->assertSame('$1,234.50', $this->formatter->formatPrice(1234.5, CurrencyOption::USD));
        $this->assertSame('C$1,234.50', $this->formatter->formatPrice(1234.5, CurrencyOption::CAD));
        $this->assertSame('R$1.234,50', $this->formatter->formatPrice(1234.5, CurrencyOption::BRL));
        $this->assertSame('€1.234,50', $this->formatter->formatPrice(1234.5, CurrencyOption::EUR));
        $this->assertSame('CHF1,234.50', $this->formatter->formatPrice(1234.5, CurrencyOption::CHF));
        $this->assertSame('£1,234.50', $this->formatter->formatPrice(1234.5, CurrencyOption::GBP));
    }

    public function test_throws_for_an_unsupported_currency(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid currency type');
        $this->formatter->formatPrice(10.0, CurrencyOption::JPY);
    }
}
