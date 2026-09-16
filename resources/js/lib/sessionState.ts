/** Session-scoped preferences (cleared when the browser tab/session ends). */
export function loadSessionState<T>(key: string): T | null {
    try {
        const raw = window.sessionStorage.getItem(key);
        if (!raw) return null;
        return JSON.parse(raw) as T;
    } catch {
        return null;
    }
}

export function saveSessionState<T>(key: string, value: T): void {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // ignore (storage disabled/quota)
    }
}

export function clearSessionState(key: string): void {
    try {
        window.sessionStorage.removeItem(key);
    } catch {
        // ignore
    }
}
