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
