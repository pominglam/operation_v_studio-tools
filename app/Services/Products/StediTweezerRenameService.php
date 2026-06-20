<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Support\Products\Storefront\StediTweezerTitleResolver;

final class StediTweezerRenameService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly StediTweezerTitleResolver $titles,
    ) {}

    /**
     * @return array{
     *   matched:int,
     *   changed:int,
     *   preview:array<int, array{sku:string, old:string, new:string}>
     * }
     */
    public function rename(bool $apply, int $previewLimit = 25): array
    {
        $matched = 0;
        $changed = 0;
        $preview = [];

        $products = $this->products->findBySkus($this->titles->supportedSkus());

        foreach ($products as $product) {
            $matched++;
            $old = (string) $product->description;
            $new = (string) $this->titles->resolveTitle($product);

            if ($new === '' || $new === $old) {
                continue;
            }

            if (count($preview) < $previewLimit) {
                $preview[] = [
                    'sku' => (string) $product->sku,
                    'old' => $old,
                    'new' => $new,
                ];
            }

            if ($apply) {
                $product->description = $new;
                $this->products->save($product);
            }

            $changed++;
        }

        return [
            'matched' => $matched,
            'changed' => $changed,
            'preview' => $preview,
        ];
    }
}
