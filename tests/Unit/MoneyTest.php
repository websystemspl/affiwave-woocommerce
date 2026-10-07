<?php

namespace AffiWave\WooCommerce\Tests\Unit;

use AffiWave\WooCommerce\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testNetIsTotalMinusOrderTax(): void
    {
        self::assertSame('35.00', Money::net('43.05', '8.05'));     // PL 23 %
        self::assertSame('119.00', Money::net('119.00', '0.00'));   // reverse charge
        self::assertSame('121.95', Money::net(149.99, 28.04));
        self::assertSame('0.00', Money::net('5.00', '6.00'));        // never negative
    }

    public function testFormatsForAffiWave(): void
    {
        self::assertSame('0.05', Money::from_cents(5));
        self::assertSame('1234.50', Money::normalize('1234.5'));
        self::assertSame('20.00', Money::normalize('20'));
        self::assertMatchesRegularExpression('/^\d{1,12}(\.\d{1,2})?$/', Money::net('0.1', '0.0'));
    }
}
