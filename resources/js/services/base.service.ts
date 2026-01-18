import api from '@/lib/api';
import type { AxiosInstance, AxiosResponse, AxiosError } from 'axios';
import type { ApiResponse, ApiError } from '@/types';

export abstract class BaseService {
  protected api: AxiosInstance;

  constructor() {
    this.api = api;
  }

  /**
   * Handle API errors consistently
   */
  protected handleError(error: unknown): never {
    if (this.isAxiosError(error)) {
      const apiError: ApiError = {
        message: error.response?.data?.message || 'An error occurred',
        errors: error.response?.data?.errors,
      };
      throw apiError;
    }

    // Handle Zod validation errors
    if (this.isZodError(error)) {
      const firstIssue = error.issues?.[0];
      const message = firstIssue?.message || 'Validation failed';
      const fieldName = firstIssue?.path?.[0];

      const apiError: ApiError = {
        message: fieldName ? `${String(fieldName)}: ${message}` : message,
        errors: error.issues?.reduce((acc: Record<string, string[]>, issue: any) => {
          const field = String(issue.path?.[0] || 'general');
          if (!acc[field]) acc[field] = [];
          acc[field].push(issue.message);
          return acc;
        }, {}),
      };
      throw apiError;
    }

    // Handle generic errors with message
    if (error instanceof Error) {
      throw new Error(error.message);
    }

    throw new Error('An unexpected error occurred');
  }

  /**
   * Type guard for Zod errors
   */
  protected isZodError(error: unknown): error is { issues: Array<{ path: Array<string | number>; message: string }> } {
    return (
      typeof error === 'object' &&
      error !== null &&
      'issues' in error &&
      Array.isArray((error as any).issues)
    );
  }

  /**
   * Type guard for Axios errors
   */
  protected isAxiosError(error: unknown): error is AxiosError<ApiError> {
    return (error as AxiosError).isAxiosError === true;
  }

  /**
   * Extract data from Axios response
   */
  protected extractData<T>(response: AxiosResponse<ApiResponse<T>>): T {
    return response.data.data;
  }
}
