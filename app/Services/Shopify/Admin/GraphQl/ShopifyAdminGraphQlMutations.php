<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\GraphQl;

final class ShopifyAdminGraphQlMutations
{
    public const string PRODUCT_SET = <<<'GQL'
        mutation productSetCreate($productSet: ProductSetInput!, $synchronous: Boolean!) {
            productSet(synchronous: $synchronous, input: $productSet) {
                product {
                    id
                    handle
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string PRODUCT_UPDATE = <<<'GQL'
        mutation productUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    handle
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string PRODUCT_DELETE_MEDIA = <<<'GQL'
        mutation productDeleteMedia($productId: ID!, $mediaIds: [ID!]!) {
            productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
                deletedMediaIds
                mediaUserErrors {
                    field
                    message
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string PUBLISHABLE_PUBLISH = <<<'GQL'
        mutation publishablePublish($id: ID!, $input: [PublicationInput!]!) {
            publishablePublish(id: $id, input: $input) {
                publishable {
                    resourcePublicationsCount {
                        count
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string TAGS_REMOVE = <<<'GQL'
        mutation tagsRemove($id: ID!, $tags: [String!]!) {
            tagsRemove(id: $id, tags: $tags) {
                node {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string TAGS_ADD = <<<'GQL'
        mutation tagsAdd($id: ID!, $tags: [String!]!) {
            tagsAdd(id: $id, tags: $tags) {
                node {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string COLLECTION_REORDER_PRODUCTS = <<<'GQL'
        mutation collectionReorderProducts($id: ID!, $moves: [MoveInput!]!) {
            collectionReorderProducts(id: $id, moves: $moves) {
                job {
                    id
                    done
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string COLLECTION_CREATE = <<<'GQL'
        mutation collectionCreate($input: CollectionInput!) {
            collectionCreate(input: $input) {
                collection {
                    id
                    handle
                    title
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string COLLECTION_UPDATE = <<<'GQL'
        mutation collectionUpdate($input: CollectionInput!) {
            collectionUpdate(input: $input) {
                collection {
                    id
                    handle
                    title
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string MENU_UPDATE = <<<'GQL'
        mutation menuUpdate($id: ID!, $title: String!, $handle: String!, $items: [MenuItemUpdateInput!]!) {
            menuUpdate(id: $id, title: $title, handle: $handle, items: $items) {
                menu {
                    id
                    handle
                    title
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

    public const string INVENTORY_SET_QUANTITIES = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup {
                    reason
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;
}
