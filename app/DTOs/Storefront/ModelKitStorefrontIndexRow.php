<?php

declare(strict_types=1);

namespace App\DTOs\Storefront;

final class ModelKitStorefrontIndexRow
{
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly string $image,
        public readonly int $priceCents,
        public readonly bool $available,
        public readonly bool $latestArrival,
        public readonly string $arrived,
        public readonly string $grade,
        public readonly string $sublines,
        public readonly string $series,
        public readonly string $lines,
        public readonly bool $preorderClosed = false,
        public readonly bool $openPreorder = false,
    ) {}

    /**
     * Compact keys keep the Shopify theme file small.
     *
     * @return array{
     *   h: string,
     *   t: string,
     *   i: string,
     *   c: int,
     *   a: int,
     *   la: int,
     *   ar: string,
     *   g: string,
     *   su: string,
     *   s: string,
     *   l: string,
     *   pc: int,
     *   op: int
     * }
     */
    public function toCompactArray(): array
    {
        return [
            'h' => $this->handle,
            't' => $this->title,
            'i' => $this->image,
            'c' => $this->priceCents,
            'a' => $this->available ? 1 : 0,
            'la' => $this->latestArrival ? 1 : 0,
            'ar' => $this->arrived,
            'g' => $this->grade,
            'su' => $this->sublines,
            's' => $this->series,
            'l' => $this->lines,
            'pc' => $this->preorderClosed ? 1 : 0,
            'op' => $this->openPreorder ? 1 : 0,
        ];
    }
}
