<?php

declare(strict_types=1);

namespace App\DTOs\Storefront;

final class ModelKitStorefrontIndexDocument
{
    /**
     * @param  array<int, ModelKitStorefrontIndexRow>  $products
     */
    public function __construct(
        public readonly string $generatedAt,
        public readonly array $products,
    ) {}

    /**
     * @return array{v: int, t: string, p: array<int, array<string, mixed>>}
     */
    public function toCompactArray(): array
    {
        return [
            'v' => 1,
            't' => $this->generatedAt,
            'p' => array_map(
                static fn (ModelKitStorefrontIndexRow $row): array => $row->toCompactArray(),
                $this->products,
            ),
        ];
    }
}
