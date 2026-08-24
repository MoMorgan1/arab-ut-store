<?php

namespace Tests\Unit\Salla;

use App\Imports\Salla\MoneyParser;
use PHPUnit\Framework\TestCase;

final class MoneyParserTest extends TestCase
{
    public function test_parses_exact_integers_and_decimals(): void
    {
        // Exact values requested by owner contract
        $this->assertSame(1600, MoneyParser::parse('16'));
        $this->assertSame(0, MoneyParser::parse('0'));
        $this->assertSame(300_000, MoneyParser::parse('3000'));
        $this->assertSame(3920, MoneyParser::parse('39.2'));

        // Additional edge cases
        $this->assertSame(3920, MoneyParser::parse('39.20'));
        $this->assertSame(3925, MoneyParser::parse('39.25'));
        $this->assertSame(3926, MoneyParser::parse('39.255')); // rounds to nearest halalah
        $this->assertSame(123_456, MoneyParser::parse('1,234.56'));
        $this->assertSame(0, MoneyParser::parse(''));
        $this->assertSame(0, MoneyParser::parse(null));
        $this->assertSame(0, MoneyParser::parse('\N'));
        $this->assertSame(0, MoneyParser::parse('NULL'));
        $this->assertSame(1600, MoneyParser::parse(16));
        $this->assertSame(0, MoneyParser::parse(0));
    }
}
