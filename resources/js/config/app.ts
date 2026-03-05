export const appConfig = {
    name: window.WorkSphere?.name || import.meta.env.VITE_APP_NAME || 'WorkSphere',
    features: {
        publicPricingEnabled: window.WorkSphere?.features?.public_pricing_page_enabled ?? true,
    },
    social: {
        twitter: window.WorkSphere?.social?.twitter || '',
        github: window.WorkSphere?.social?.github || '',
        linkedin: window.WorkSphere?.social?.linkedin || '',
    }
};
