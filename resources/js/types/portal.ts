export type PortalLocale = 'ru' | 'en';

export type PortalUrls = {
    home: string;
    services: string;
    bookings: string;
    health: string;
    more: string;
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

export type PortalNavKey = 'home' | 'bookings' | 'health' | 'companion' | 'more' | null;
