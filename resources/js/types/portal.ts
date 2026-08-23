export type PortalLocale = 'ru' | 'en';

export type PortalUrls = {
    home: string;
    services: string;
    bookings: string;
    profile: string;
    finance: string;
    surveys: string;
    companion: string;
    booking: string;
};

export type PortalShell = {
    authenticated: boolean;
    clientName: string | null;
    locale: PortalLocale;
    localeUrl: string;
    urls: PortalUrls;
};

export type PortalNavKey = 'home' | 'services' | 'bookings' | 'finance' | 'surveys' | 'companion' | 'profile' | null;
