<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\GraphQl;

final class ShopifyAdminGraphQlQueries
{
    public const PUBLICATIONS_PAGE = <<<'GQL'
query Publications($first: Int!, $after: String) {
  publications(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
    }
  }
}
GQL;

    public const LOCATIONS_PAGE = <<<'GQL'
query Locations($first: Int!, $after: String) {
  locations(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      name
      isActive
      fulfillsOnlineOrders
      updatedAt
    }
  }
}
GQL;

    public const PRODUCTS_PAGE = <<<'GQL'
query Products($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      handle
      title
      status
      vendor
      updatedAt
      variants(first: 100) {
        nodes {
          id
          legacyResourceId
          sku
          barcode
          inventoryQuantity
          updatedAt
          inventoryItem {
            id
            legacyResourceId
            sku
            tracked
            requiresShipping
            updatedAt
          }
        }
      }
    }
  }
}
GQL;

    /**
     * Read-only storefront connectivity test; not used by sync runners (no persistence).
     *
     * @see \App\Console\Commands\ShopifyTestProductsCommand
     */
    public const PRODUCTS_CONNECTIVITY_PREVIEW = <<<'GQL'
query ProductsConnectivityPreview($first: Int!) {
  products(first: $first) {
    nodes {
      id
      handle
      title
      status
      vendor
      productType
      variants(first: 250) {
        pageInfo { hasNextPage }
        nodes {
          id
        }
      }
    }
  }
}
GQL;

    public const INVENTORY_ITEM_LEVELS = <<<'GQL'
query InventoryItemLevels($id: ID!, $first: Int!, $after: String) {
  inventoryItem(id: $id) {
    id
    inventoryLevels(first: $first, after: $after) {
      pageInfo { hasNextPage endCursor }
      nodes {
        id
        quantities(names: ["available"]) {
          name
          quantity
        }
        location {
          id
        }
        updatedAt
      }
    }
  }
}
GQL;

    public const ORDERS_PAGE = <<<'GQL'
query Orders($first: Int!, $after: String) {
  orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: true) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      name
      displayFinancialStatus
      displayFulfillmentStatus
      cancelledAt
      createdAt
      updatedAt
      lineItems(first: 100) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id
          sku
          quantity
          variant { id sku }
        }
      }
    }
  }
}
GQL;

    public const ORDERS_INCREMENTAL_PAGE = <<<'GQL'
query OrdersIncremental($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, sortKey: UPDATED_AT, reverse: false, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      name
      displayFinancialStatus
      displayFulfillmentStatus
      cancelledAt
      createdAt
      updatedAt
      lineItems(first: 100) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id
          sku
          quantity
          variant { id sku }
        }
      }
    }
  }
}
GQL;

    public const ORDER_BY_ID = <<<'GQL'
query OrderById($id: ID!) {
  order(id: $id) {
    id
    legacyResourceId
    name
    displayFinancialStatus
    displayFulfillmentStatus
    cancelledAt
    createdAt
    updatedAt
    lineItems(first: 100) {
      pageInfo { hasNextPage endCursor }
      nodes {
        id
        sku
        quantity
        variant { id sku }
      }
    }
  }
}
GQL;

    public const CUSTOMERS_PAGE = <<<'GQL'
query Customers($first: Int!, $after: String) {
  customers(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      displayName
      defaultEmailAddress { emailAddress }
      createdAt
      updatedAt
    }
  }
}
GQL;

    public const string COLLECTION_BY_HANDLE = <<<'GQL'
query CollectionByHandle($handle: String!) {
  collectionByHandle(handle: $handle) {
    id
    handle
    title
    productsCount {
      count
    }
  }
}
GQL;

    public const string MENU_BY_HANDLE = <<<'GQL'
query MenuByHandle($query: String!) {
  menus(first: 1, query: $query) {
    nodes {
      id
      handle
      title
      items {
        id
        title
        url
        type
        resourceId
        items {
          id
          title
          url
          type
          resourceId
          items {
            id
            title
            url
            type
            resourceId
            items {
              id
              title
              url
              type
              resourceId
            }
          }
        }
      }
    }
  }
}
GQL;

    public const string COLLECTION_PRODUCTS_PREVIEW = <<<'GQL'
query CollectionProductsPreview($id: ID!, $first: Int!) {
  collection(id: $id) {
    id
    handle
    title
    productsCount {
      count
    }
    products(first: $first) {
      nodes {
        id
        handle
        tags
      }
    }
  }
}
GQL;

    public const string PRODUCT_TAGS_BY_ID = <<<'GQL'
query ProductTagsById($id: ID!) {
  product(id: $id) {
    id
    handle
    tags
  }
}
GQL;

    public const COLLECTIONS_PAGE = <<<'GQL'
query Collections($first: Int!, $after: String) {
  collections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      handle
      title
      updatedAt
    }
  }
}
GQL;

    public const PRODUCT_MEDIA_IDS = <<<'GQL'
query ProductMediaIds($id: ID!, $first: Int!, $after: String) {
  product(id: $id) {
    id
    media(first: $first, after: $after) {
      pageInfo { hasNextPage endCursor }
      nodes {
        id
      }
    }
  }
}
GQL;

    public const PRODUCT_MEDIA_STATUS = <<<'GQL'
query ProductMediaStatus($id: ID!, $first: Int!, $after: String) {
  product(id: $id) {
    id
    media(first: $first, after: $after) {
      pageInfo { hasNextPage endCursor }
      nodes {
        ... on MediaImage {
          id
          status
          mediaContentType
          mediaErrors {
            code
            details
            message
          }
        }
      }
    }
  }
}
GQL;
}
