export function formatMajorPrice(amount: string, currency: string, locale: string): string {
    const [whole, fraction] = amount.split('.');
    const formatter = new Intl.NumberFormat(locale === 'ru' ? 'ru-RU' : 'en-GB', {
        currency,
        maximumFractionDigits: 0,
        style: 'currency',
    });
    const parts = formatter.formatToParts(BigInt(whole));

    if (fraction === undefined) {
        return parts.map((part) => part.value).join('');
    }

    const lastNumberPart = parts.findLastIndex((part) => part.type === 'integer' || part.type === 'group');

    if (lastNumberPart === -1) {
        return parts.map((part) => part.value).join('') + '.' + fraction;
    }

    parts.splice(
        lastNumberPart + 1,
        0,
        { type: 'decimal', value: locale === 'ru' ? ',' : '.' },
        { type: 'fraction', value: fraction },
    );

    return parts.map((part) => part.value).join('');
}
