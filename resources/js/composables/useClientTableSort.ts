import { ref, type Ref } from 'vue';

export type SortDirection = 'asc' | 'desc';

export type ClientTableSort<C extends string> = {
    sortBy: Ref<C | null>;
    sortDir: Ref<SortDirection>;
    toggleSort: (column: C) => void;
    sortIndicator: (column: C) => string;
    headerClass: (column: C, inactiveClass?: string) => string;
    reset: () => void;
    sortedRows: <T>(rows: T[], compare: (a: T, b: T, column: C) => number) => T[];
};

export function useClientTableSort<C extends string>(): ClientTableSort<C> {
    const sortBy = ref<C | null>(null) as Ref<C | null>;
    const sortDir = ref<SortDirection>('asc');

    function toggleSort(column: C): void {
        if (sortBy.value === column) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
            return;
        }

        sortBy.value = column;
        sortDir.value = 'asc';
    }

    function sortIndicator(column: C): string {
        if (sortBy.value !== column) return '';

        return sortDir.value === 'asc' ? ' ▲' : ' ▼';
    }

    function headerClass(column: C, inactiveClass = 'text-slate-500'): string {
        return sortBy.value === column ? 'text-slate-900' : inactiveClass;
    }

    function reset(): void {
        sortBy.value = null;
        sortDir.value = 'asc';
    }

    function sortedRows<T>(rows: T[], compare: (a: T, b: T, column: C) => number): T[] {
        if (sortBy.value === null) return rows;

        const column = sortBy.value;
        const direction = sortDir.value;

        return [...rows].sort((left, right) => {
            const result = compare(left, right, column);
            return direction === 'asc' ? result : -result;
        });
    }

    return {
        sortBy,
        sortDir,
        toggleSort,
        sortIndicator,
        headerClass,
        reset,
        sortedRows,
    };
}
