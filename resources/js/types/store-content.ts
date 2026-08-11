export type HomeServiceCard = {
    description: string;
    external: boolean;
    href: string;
    imageUrl: string;
    key: 'sbc' | 'objectives' | 'fut_champions' | 'rivals' | 'sell_coins';
    title: string;
};

export type ServiceRailTranslations = {
    eyebrow: string;
    title: string;
};

export type StoreHomeContent = {
    services: HomeServiceCard[];
    servicesTranslations: ServiceRailTranslations;
};
