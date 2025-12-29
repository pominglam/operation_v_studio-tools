export function loadPageState<T>(key: string): T | null {
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return null;
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
}

export function savePageState<T>(key: string, value: T): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // ignore (storage disabled/quota)
    }
}

export function clearPageState(key: string): void {
    try {
        window.localStorage.removeItem(key);
    } catch {
        // ignore
    }
}


