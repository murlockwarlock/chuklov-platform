export function formatServiceText(value: string): string {
    const normalized = value.replace(/\r\n?/g, '\n');

    if (!/\b1\.\s+/.test(normalized) || !/\b2\.\s+/.test(normalized)) {
        return normalized;
    }

    return normalized.replace(/(\S)[ \t]+(?=\d+\.\s+)/g, '$1\n');
}
