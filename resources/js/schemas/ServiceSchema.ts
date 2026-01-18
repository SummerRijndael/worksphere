import { z } from 'zod';

export const ServiceSchema = z.object({
    id: z.string().optional(), // Optional for creation
    name: z.string().min(1, 'Name is required'),
    description: z.string().min(1, 'Description is required'),
    price: z.number().min(0, 'Price must be non-negative'),
    currency: z.string().default('$'),
    interval: z.enum(['month', 'year', 'forever']).default('month'),
    features: z.array(z.string()).default([]),
    is_popular: z.boolean().default(false),
    cta_text: z.string().default('Get Started'),
    active: z.boolean().default(true),
    // For styling purposes to match the 'premium' look (can be used for customization later)
    color_theme: z.enum(['gray', 'orange', 'blue']).default('gray'), 
});

export type Service = z.infer<typeof ServiceSchema>;

export const defaultServiceValues: Partial<Service> = {
    name: '',
    description: '',
    price: 0,
    currency: '$',
    interval: 'month',
    features: [''],
    is_popular: false,
    cta_text: 'Get Started',
    active: true,
    color_theme: 'gray',
};
