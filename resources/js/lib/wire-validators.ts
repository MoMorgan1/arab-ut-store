const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
const UTC_TIMESTAMP_PATTERN =
    /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,3}))?(?:Z|\+00:00)$/;

export function isWireUlid(value: unknown): value is string {
    return typeof value === 'string' && ULID_PATTERN.test(value);
}

export function isUtcWireTimestamp(value: unknown): value is string {
    if (typeof value !== 'string') {
        return false;
    }

    const match = UTC_TIMESTAMP_PATTERN.exec(value);

    if (match === null) {
        return false;
    }

    const normalized = `${match[1]}.${(match[2] ?? '').padEnd(3, '0')}Z`;
    const timestamp = new Date(value);

    return (
        Number.isFinite(timestamp.getTime()) &&
        timestamp.toISOString() === normalized
    );
}
