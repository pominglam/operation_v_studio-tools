export type StoreMarketingNote = {
    id: string;
    name: string;
    happened_on: string;
    notes: string | null;
};

export type StoreMarketingNoteWrite = {
    name: string;
    happened_on: string;
    notes: string;
};
