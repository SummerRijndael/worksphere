const DEBUG_STORAGE_KEY = 'worksphere_debug_support_chat';

type LogLevel = 'debug' | 'info' | 'warn' | 'error';

function debugFlagFromStorage(): boolean {
    try {
        const raw = localStorage.getItem(DEBUG_STORAGE_KEY);
        if (!raw) {
            return false;
        }

        const normalized = raw.trim().toLowerCase();
        return ['1', 'true', 'yes', 'on', 'enabled'].includes(normalized);
    } catch {
        return false;
    }
}

function isSupportDebugEnabled(): boolean {
    const runtimeFlag = (window as any)?.WorkSphere?.debug?.supportChat;
    if (typeof runtimeFlag === 'boolean') {
        return runtimeFlag;
    }

    return debugFlagFromStorage() || Boolean(import.meta.env.DEV);
}

function nowIso(): string {
    return new Date().toISOString();
}

function prefix(scope: string, phase: string): string {
    return `[SupportChat][${scope}][${phase}]`;
}

function log(level: LogLevel, scope: string, phase: string, message: string, data?: unknown): void {
    if (!isSupportDebugEnabled()) {
        return;
    }

    const line = `${prefix(scope, phase)} ${message}`;
    const payload = data === undefined ? { at: nowIso() } : { at: nowIso(), ...((typeof data === 'object' && data !== null) ? data as object : { data }) };

    if (level === 'error') {
        console.error(line, payload);
        return;
    }

    if (level === 'warn') {
        console.warn(line, payload);
        return;
    }

    if (level === 'info') {
        console.info(line, payload);
        return;
    }

    console.debug(line, payload);
}

export function summarizeError(error: any): Record<string, unknown> {
    return {
        name: error?.name,
        message: error?.message,
        code: error?.code,
        status: error?.response?.status,
        response_message: error?.response?.data?.message,
        url: error?.config?.url,
        method: error?.config?.method,
    };
}

export function maskToken(token: string | null | undefined): string {
    const value = String(token || '').trim();
    if (!value) {
        return '';
    }
    if (value.length <= 8) {
        return `${value.slice(0, 2)}***`;
    }

    return `${value.slice(0, 4)}...${value.slice(-4)}`;
}

export function createSupportLogger(scope: string) {
    return {
        debug(phase: string, message: string, data?: unknown) {
            log('debug', scope, phase, message, data);
        },
        info(phase: string, message: string, data?: unknown) {
            log('info', scope, phase, message, data);
        },
        warn(phase: string, message: string, data?: unknown) {
            log('warn', scope, phase, message, data);
        },
        error(phase: string, message: string, data?: unknown) {
            log('error', scope, phase, message, data);
        },
    };
}

