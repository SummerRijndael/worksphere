import type { User } from '../models/user';

export interface ApiResponse<T = any> {
  data: T;
  message?: string;
  meta?: {
    current_page: number;
    from: number;
    last_page: number;
    per_page: number;
    to: number;
    total: number;
  };
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  first_page_url: string;
  from: number;
  last_page: number;
  last_page_url: string;
  links: Array<{
    url: string | null;
    label: string;
    active: boolean;
  }>;
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number;
  total: number;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

export interface LoginRequest {
  email?: string;
  public_id?: string;
  password: string;
  remember?: boolean;
  recaptcha_token?: string;
  recaptcha_v2_token?: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  recaptcha_token?: string;
}

export interface AuthResponse {
  user: User;
  requires_2fa?: boolean;
  methods?: string[];
}
