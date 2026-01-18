import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().optional(),
  public_id: z.string().optional(),
  password: z.string()
    .min(1, 'Password is required'),
  remember: z.boolean().optional().default(false),
  recaptcha_token: z.string().optional(),
  recaptcha_v2_token: z.string().optional(),
}).refine((data) => data.email || data.public_id, {
  message: "Email or Username is required",
  path: ["email"],
});

export const registerSchema = z.object({
  name: z.string()
    .min(1, 'Name is required')
    .max(255, 'Name is too long'),
  email: z.string()
    .email('Please enter a valid email address')
    .min(1, 'Email is required'),
  password: z.string()
    .min(8, 'Password must be at least 8 characters')
    .regex(/[a-z]/, 'Password must contain at least one lowercase letter')
    .regex(/[A-Z]/, 'Password must contain at least one uppercase letter')
    .regex(/[0-9]/, 'Password must contain at least one number')
    .regex(/[^A-Za-z0-9]/, 'Password must contain at least one special character'),
  password_confirmation: z.string(),
  recaptcha_token: z.string().optional(),
}).refine(data => data.password === data.password_confirmation, {
  message: "Passwords don't match",
  path: ['password_confirmation'],
});

export const forgotPasswordSchema = z.object({
  email: z.string().email('Please enter a valid email address'),
});

export const twoFactorSchema = z.object({
  code: z.string()
    .length(6, 'Code must be 6 digits')
    .regex(/^\d+$/, 'Code must only contain numbers'),
  method: z.enum(['totp', 'sms', 'email']).default('totp'),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>;
export type TwoFactorInput = z.infer<typeof twoFactorSchema>;
