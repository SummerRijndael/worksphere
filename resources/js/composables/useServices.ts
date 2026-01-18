import { ref } from 'vue';
import type { Service } from '@/schemas/ServiceSchema';

// Mock Data Initial State matching the screenshot
const mockServices: Service[] = [
    {
        id: '1',
        name: 'Starter',
        description: 'Perfect for individuals and small teams getting started.',
        price: 0,
        currency: '$',
        interval: 'forever',
        features: [
            'Up to 5 team members',
            '10 projects',
            'Basic analytics',
            'Email support',
            '5GB storage'
        ],
        is_popular: false,
        cta_text: 'Get Started Free',
        active: true,
        color_theme: 'gray'
    },
    {
        id: '2',
        name: 'Professional',
        description: 'For growing teams that need more power and flexibility.',
        price: 29,
        currency: '$',
        interval: 'per user/month',
        features: [
            'Unlimited team members',
            'Unlimited projects',
            'Advanced analytics',
            'Priority support',
            '100GB storage',
            'Custom workflows',
            'API access'
        ],
        is_popular: true,
        cta_text: 'Start Free Trial',
        active: true,
        color_theme: 'orange'
    },
    {
        id: '3',
        name: 'Enterprise',
        description: 'For large organizations with custom requirements.',
        price: 0, // Represents "Custom"
        currency: '', // No currency for custom
        interval: 'contact us', // displayed as text
        features: [
            'Everything in Professional',
            'Dedicated account manager',
            'Custom integrations',
            'On-premise deployment',
            'Unlimited storage',
            'SLA guarantee',
            '24/7 phone support'
        ],
        is_popular: false,
        cta_text: 'Contact Sales',
        active: true,
        color_theme: 'gray'
    }
];

export function useServices() {
    const services = ref<Service[]>(mockServices);

    const getService = (id: string) => {
        return services.value.find(s => s.id === id);
    };

    const saveService = async (service: Service) => {
        // Simulate API call
        await new Promise(resolve => setTimeout(resolve, 500));
        
        if (service.id) {
            const index = services.value.findIndex(s => s.id === service.id);
            if (index !== -1) {
                services.value[index] = { ...service };
            }
        } else {
            const newService = {
                ...service,
                id: Math.random().toString(36).substr(2, 9)
            };
            services.value.push(newService);
        }
    };

    const deleteService = async (id: string) => {
         // Simulate API call
        await new Promise(resolve => setTimeout(resolve, 500));
        services.value = services.value.filter(s => s.id !== id);
    };

    return {
        services,
        getService,
        saveService,
        deleteService
    };
}
