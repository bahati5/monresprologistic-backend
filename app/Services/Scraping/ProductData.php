<?php

namespace App\Services\Scraping;

final class ProductData
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?float $price = null,
        public readonly ?string $currency = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $description = null,
        public readonly ?string $merchant = null,
        public readonly bool $success = false,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'image_url' => $this->imageUrl,
            'description' => $this->description,
            'merchant' => $this->merchant,
            'success' => $this->success,
        ];
    }
}
