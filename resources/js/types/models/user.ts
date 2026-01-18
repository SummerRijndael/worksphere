export interface User {
  id: number | string;
  public_id: string;
  name: string;
  email: string;
  email_verified_at?: string | null;
  avatar_url?: string | null;
  created_at: string;
  updated_at: string;
  is_password_set?: boolean;
  password_last_updated_at?: string | null;
  presence?: 'online' | 'offline' | 'away' | 'busy';
  roles?: Role[];
  permissions?: Permission[];
  teams?: Team[];
  two_factor_confirmed_at?: string | null;
  two_factor_email_confirmed_at?: string | null;
  two_factor_enforced?: boolean;
  two_factor_allowed_methods?: string[];
}

export interface Role {
  id: number;
  name: string;
  display_name: string;
  description?: string;
  permissions: Permission[];
  users_count?: number;
}

export interface Permission {
  id: number;
  name: string;
  display_name: string;
  category: string;
  description?: string;
}

export interface Team {
  id: number;
  public_id: string;
  name: string;
  description?: string;
  avatar?: string;
  members_count?: number;
  created_at: string;
}
