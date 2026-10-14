import reference from '../../data/profile/phones.json';

type Region = {
    names: { ru: string; en: string };
    callingCode: string;
    nationalPrefix: string;
    leading: string;
    pattern: string;
    example: string;
    formats: Array<{ pattern: string; leading: string | null; replacement: string }>;
};

const regions: Record<string, Region> = reference;

function selectedRegion(country: string): Region | undefined {
    const name = country.trim().toLocaleLowerCase();

    return Object.entries(regions).find(([code, region]) => code.toLocaleLowerCase() === name
        || Object.values(region.names).some((value) => value.toLocaleLowerCase() === name))?.[1];
}

function matches(national: string, region: Region): boolean {
    return new RegExp('^(?:' + region.pattern + ')$').test(national)
        && (region.leading === '' || new RegExp('^(?:' + region.leading + ')').test(national));
}

export function phoneExample(country: string): string {
    const region = selectedRegion(country);

    return region ? formatPhone('+' + region.callingCode + region.example, country) : '+…';
}

export function formatPhoneInput(value: string, country: string): string {
    if (!/^[+\d\s().-]*$/.test(value)) return value;

    const selected = selectedRegion(country);
    if (!selected || value.trim() === '') return value;

    const digits = value.replace(/\D/g, '');
    if (digits === '') return value.startsWith('+') ? '+' : value;

    let national = digits;
    let prefix = '';
    if (value.startsWith('+')) {
        if (!digits.startsWith(selected.callingCode)) return '+' + digits;
        prefix = '+' + selected.callingCode;
        national = digits.slice(selected.callingCode.length);
    } else if (selected.nationalPrefix !== '' && digits.startsWith(selected.nationalPrefix) && digits.length > selected.nationalPrefix.length) {
        prefix = '+' + selected.callingCode;
        national = digits.slice(selected.nationalPrefix.length);
    } else if (digits.startsWith(selected.callingCode) && digits.length > selected.callingCode.length) {
        prefix = '+' + selected.callingCode;
        national = digits.slice(selected.callingCode.length);
    } else {
        return value;
    }

    if (matches(national, selected)) return formatPhone(value, country);

    const format = selected.formats.find((candidate) => {
        try {
            return !candidate.leading || new RegExp('^(?:' + candidate.leading + ')').test(national);
        } catch {
            return false;
        }
    }) ?? selected.formats[0];
    if (!format) return prefix + (national === '' ? '' : ' ' + national);

    const groups = [...format.pattern.matchAll(/\\d\{(\d+)(?:,\d+)?\}/g)].map((match) => Number(match[1]));
    if (groups.length === 0) return prefix + (national === '' ? '' : ' ' + national);
    const chunks: string[] = [];
    let offset = 0;
    for (const size of groups) {
        if (offset >= national.length) break;
        chunks.push(national.slice(offset, offset + size));
        offset += size;
    }
    if (offset < national.length) chunks.push(national.slice(offset));
    const separators = format.replacement.split(/\$\d+/).slice(1);
    const formatted = chunks.reduce((result, chunk, index) =>
        result + (index === 0 ? '' : (separators[index - 1] ?? ' ')) + chunk, '');

    return prefix + (formatted === '' ? '' : ' ' + formatted);
}

export function formatPhone(value: string, country: string): string {
    if (!/^[+\d\s().-]+$/.test(value)) return value;
    const digits = value.replace(/\D/g, '');
    const selected = selectedRegion(country);
    let region: Region | undefined;
    let national = '';
    if (!value.startsWith('+') && selected) {
        national = digits;
        if (!matches(national, selected) && selected.nationalPrefix !== '' && national.startsWith(selected.nationalPrefix)) {
            national = national.slice(selected.nationalPrefix.length);
        }
        if (matches(national, selected)) region = selected;
    }
    if (!region) {
        region = Object.values(regions).find((candidate) => digits.startsWith(candidate.callingCode)
            && matches(digits.slice(candidate.callingCode.length), candidate));
        if (region) national = digits.slice(region.callingCode.length);
    }
    if (!region) return value;
    const format = region.formats.find((candidate) => (!candidate.leading || new RegExp('^(?:' + candidate.leading + ')').test(national))
        && new RegExp('^(?:' + candidate.pattern + ')$').test(national));

    return '+' + region.callingCode + ' ' + (format ? national.replace(new RegExp('^(?:' + format.pattern + ')$'), format.replacement) : national);
}
