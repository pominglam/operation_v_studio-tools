export type CustomAsiaOrderProductNameSuggestion = {
    title: string;
    price_cad: string | null;
    source_key: string;
    source_name: string;
    product_url: string | null;
    availability: 'in_stock' | 'sold_out' | null;
};

export type CustomAsiaOrderProductNameSuggestionsResponse = {
    data: CustomAsiaOrderProductNameSuggestion[];
};
