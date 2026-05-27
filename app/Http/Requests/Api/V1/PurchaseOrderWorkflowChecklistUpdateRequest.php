<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseOrderWorkflowChecklistUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'import_po' => ['sometimes', 'boolean'],
            'crawl_desc_image_price' => ['sometimes', 'boolean'],
            'select_and_arrange_product_images' => ['sometimes', 'boolean'],
            'select_and_arrange_product_images_deferred' => ['sometimes', 'boolean'],
            'set_selling_price' => ['sometimes', 'boolean'],
            'ensure_all_products_have_barcode' => ['sometimes', 'boolean'],
            'export_to_shopify_get_handles' => ['sometimes', 'boolean'],
            'import_handle_only' => ['sometimes', 'boolean'],
            'update_product_available_with_shopify_current_inventory_quantity' => ['sometimes', 'boolean'],
            'import_product_available_quantity' => ['sometimes', 'boolean'],
            'mark_latest_arrival_and_published_on_shopify' => ['sometimes', 'boolean'],
        ];
    }
}
