export function interpolate(
    template: string,
    values: Record<string, string | number>,
): string {
    return Object.entries(values).reduce(
        (copy, [key, value]) => copy.replaceAll(`:${key}`, String(value)),
        template,
    );
}
