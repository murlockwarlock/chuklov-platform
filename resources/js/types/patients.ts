export type PatientIdentity = {
    first_name: string;
    last_name: string;
    middle_name: string;
    gender: string;
    birth_date: string;
    relationship: string;
};

export type PatientSummary = {
    id: number;
    name: string;
    relationship: string;
    relationshipLabel: string;
    age: number | null;
    birthDate: string | null;
    linked: boolean;
    revoked: boolean;
    canInvite: boolean;
    canBook: boolean;
    canRevoke: boolean;
    canEdit: boolean;
    identity: PatientIdentity;
    updateUrl: string;
    medicalUrl: string | null;
    bookingUrl: string;
    invitationUrl: string;
    revokeUrl: string;
};

export function emptyPatientIdentity(): PatientIdentity {
    return { first_name: '', last_name: '', middle_name: '', gender: '', birth_date: '', relationship: '' };
}
