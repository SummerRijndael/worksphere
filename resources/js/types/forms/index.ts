import type { TicketPriority, TicketType } from '../models/ticket';

export interface LoginForm {
  email: string;
  password: string;
  remember: boolean;
}

export interface RegisterForm {
  name: string;
  email: string;
  password: string;
  confirmPassword: string;
  terms: boolean;
}

export interface TicketForm {
  title: string;
  description: string;
  priority: TicketPriority;
  type: TicketType;
  tags: string[];
}
