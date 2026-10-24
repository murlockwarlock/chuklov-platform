export type PersonalData = {
    first_name: string;
    last_name: string;
    middle_name: string;
    gender: string;
    birth_date: string;
    country: string;
    city: string;
    phone: string;
    email: string;
};

export type PersonalProfile = {
    firstName: string | null;
    lastName: string | null;
    middleName: string | null;
    gender: string | null;
    birthDate: string | null;
    country: string | null;
    city: string | null;
    phone: string | null;
    email: string | null;
    emailEditable: boolean;
};

export function personalData(profile: PersonalProfile): PersonalData {
    return {
        first_name: profile.firstName ?? '', last_name: profile.lastName ?? '', middle_name: profile.middleName ?? '',
        gender: profile.gender ?? '', birth_date: profile.birthDate ?? '', country: profile.country ?? '',
        city: profile.city ?? '', phone: profile.phone ?? '', email: profile.email ?? '',
    };
}
