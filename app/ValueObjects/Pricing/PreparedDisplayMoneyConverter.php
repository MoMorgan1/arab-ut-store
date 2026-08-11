<?php

namespace App\ValueObjects\Pricing;

use App\Support\Money;
use DomainException;

final readonly class PreparedDisplayMoneyConverter
{
    private const RATE_SCALE = 100_000_000;

    private function __construct(
        public string $currency,
        private ?int $scaledRate,
    ) {}

    public static function sar(): self
    {
        return new self('SAR', null);
    }

    public static function fromRate(string $currency, mixed $rate): self
    {
        if (! is_string($rate) || preg_match('/^(?<whole>\d{1,12})\.(?<fraction>\d{8})$/D', $rate, $matches) !== 1) {
            throw new DomainException('The display exchange rate is invalid.');
        }

        $digits = ltrim($matches['whole'].$matches['fraction'], '0');

        if ($digits === '') {
            throw new DomainException('The display exchange rate must be positive.');
        }

        $scaledRate = 0;

        foreach (str_split($digits) as $digit) {
            $value = ord($digit) - ord('0');

            if ($scaledRate > intdiv(PHP_INT_MAX - $value, 10)) {
                throw new DomainException('The display exchange rate is too large.');
            }

            $scaledRate = ($scaledRate * 10) + $value;
        }

        return new self($currency, $scaledRate);
    }

    /** @return array{amountMinor: int, currency: string} */
    public function convert(Money $money): array
    {
        if ($this->currency === 'SAR') {
            return ['amountMinor' => $money->halalah(), 'currency' => 'SAR'];
        }

        if ($this->scaledRate === null) {
            throw new DomainException('The display exchange rate is unavailable.');
        }

        $halalah = $money->halalah();

        if ($this->scaledRate !== 0 && $halalah > intdiv(PHP_INT_MAX, $this->scaledRate)) {
            throw new DomainException('The display money conversion would overflow.');
        }

        $scaledAmount = $halalah * $this->scaledRate;
        $amountMinor = intdiv($scaledAmount, self::RATE_SCALE);

        if ($scaledAmount % self::RATE_SCALE >= intdiv(self::RATE_SCALE, 2)) {
            if ($amountMinor === PHP_INT_MAX) {
                throw new DomainException('The display money conversion would overflow.');
            }

            $amountMinor++;
        }

        if ($amountMinor <= 0) {
            throw new DomainException('The display money conversion is too small.');
        }

        return ['amountMinor' => $amountMinor, 'currency' => $this->currency];
    }
}
