<?php

namespace App\Support;

use DomainException;
use InvalidArgumentException;

final readonly class Money
{
    private function __construct(private int $halalah) {}

    public static function fromHalalah(int $halalah): self
    {
        if ($halalah < 0) {
            throw new InvalidArgumentException('A money amount cannot be negative.');
        }

        return new self($halalah);
    }

    public function halalah(): int
    {
        return $this->halalah;
    }

    public function currency(): string
    {
        return 'SAR';
    }

    public function plus(self $other): self
    {
        if ($other->halalah > PHP_INT_MAX - $this->halalah) {
            throw new DomainException('A money operation cannot overflow a signed 64-bit amount.');
        }

        return self::fromHalalah($this->halalah + $other->halalah);
    }

    public function minus(self $other): self
    {
        if ($other->halalah > $this->halalah) {
            throw new DomainException('A money operation cannot result in a negative amount.');
        }

        return self::fromHalalah($this->halalah - $other->halalah);
    }

    public function multiply(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('A quantity cannot be negative.');
        }

        if ($this->halalah !== 0 && $quantity > intdiv(PHP_INT_MAX, $this->halalah)) {
            throw new DomainException('A money operation cannot overflow a signed 64-bit amount.');
        }

        return self::fromHalalah($this->halalah * $quantity);
    }
}
