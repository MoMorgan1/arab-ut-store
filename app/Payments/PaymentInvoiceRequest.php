<?php

namespace App\Payments;

use InvalidArgumentException;

final readonly class PaymentInvoiceRequest
{
    /** @var list<array{title: string, priceHalalah: int, quantity: int, description?: string}> */
    public array $products;

    /**
     * @param  list<array<string, mixed>>  $products
     */
    public function __construct(
        public string $orderNumber,
        public int $amountHalalah,
        public string $callbackUrl,
        public string $cancelUrl,
        public string $clientName,
        public ?string $clientEmail,
        public string $clientMobile,
        array $products,
    ) {
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $this->orderNumber) !== 1
            || $this->amountHalalah < 500
            || ! self::isHttpsUrl($this->callbackUrl)
            || ! self::isHttpsUrl($this->cancelUrl)
            || trim($this->clientName) === ''
            || mb_strlen($this->clientName) > 160
            || ($this->clientEmail !== null && filter_var($this->clientEmail, FILTER_VALIDATE_EMAIL) === false)
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $this->clientMobile) !== 1
            || $products === []) {
            throw new InvalidArgumentException('The payment invoice request is invalid.');
        }

        $normalizedProducts = [];

        foreach ($products as $product) {
            if (array_diff(array_keys($product), ['title', 'priceHalalah', 'quantity', 'description']) !== []
                || ! isset($product['title'], $product['priceHalalah'], $product['quantity'])
                || ! is_string($product['title'])
                || trim($product['title']) === ''
                || mb_strlen($product['title']) > 200
                || ! is_int($product['priceHalalah'])
                || $product['priceHalalah'] < 0
                || ! is_int($product['quantity'])
                || $product['quantity'] < 1
                || (isset($product['description'])
                    && (! is_string($product['description']) || mb_strlen($product['description']) > 500))) {
                throw new InvalidArgumentException('A payment invoice product is invalid.');
            }

            $normalizedProduct = [
                'title' => $product['title'],
                'priceHalalah' => $product['priceHalalah'],
                'quantity' => $product['quantity'],
            ];

            if (isset($product['description'])) {
                $normalizedProduct['description'] = $product['description'];
            }

            $normalizedProducts[] = $normalizedProduct;
        }

        $this->products = $normalizedProducts;
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host']);
    }
}
