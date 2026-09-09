export type PortalLocale = 'ru' | 'en';

export type PortalUrls = {
    home: string;
    services: string;
    bookings: string;
    profile: string;
    finance: string;
    surveys: string;
    companion: string;
    tracker: string;
    referrals: string;
    feedback: string;
    attribution: string;
    booking: string;
    b2b: string;
};

export type PortalShell = {
    authenticated: boolean;
    clientName: string | null;
    locale: PortalLocale;
    localeUrl: string;
    urls: PortalUrls;
};

export type PortalNavKey = 'home' | 'services' | 'bookings' | 'finance' | 'surveys' | 'companion' | 'tracker' | 'profile' | 'referrals' | 'feedback' | 'attribution' | 'b2b' | null;
