import { toast, type ExternalToast } from 'vue-sonner';

type ToastId = string | number;

interface ToastReturn {
  toast: typeof toast;
  success: (title: string, message?: string) => ToastId;
  error: (title: string, message?: string) => ToastId;
  warning: (title: string, message?: string) => ToastId;
  info: (title: string, message?: string) => ToastId;
  promise: <T>(promiseFn: Promise<T> | (() => Promise<T>), options: {
    loading: string;
    success: string | ((data: T) => string);
    error: string | ((error: Error) => string);
  }) => ToastId;
  dismiss: (id?: ToastId) => void;
  custom: (title: string, options?: ExternalToast) => ToastId;
}

export function useToast(): ToastReturn {
  function success(title: string, message?: string): ToastId {
    return toast.success(title, {
      description: message,
    });
  }

  function error(title: string, message?: string): ToastId {
    return toast.error(title, {
      description: message,
    });
  }

  function warning(title: string, message?: string): ToastId {
    return toast.warning(title, {
      description: message,
    });
  }

  function info(title: string, message?: string): ToastId {
    return toast.info(title, {
      description: message,
    });
  }

  function promise<T>(
    promiseFn: Promise<T> | (() => Promise<T>),
    options: {
      loading: string;
      success: string | ((data: T) => string);
      error: string | ((error: Error) => string);
    }
  ): ToastId {
    return toast.promise(promiseFn, options);
  }

  function dismiss(id?: ToastId): void {
    toast.dismiss(id);
  }

  function custom(title: string, options: ExternalToast = {}): ToastId {
    return toast(title, options);
  }

  return {
    toast,
    success,
    error,
    warning,
    info,
    promise,
    dismiss,
    custom,
  };
}
