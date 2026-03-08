const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

export function isValidUlid(value: unknown): value is string {
    return typeof value === "string" && ULID_PATTERN.test(value.trim());
}

export function normalizeUlid(value: string): string {
    return value.trim().toUpperCase();
}
