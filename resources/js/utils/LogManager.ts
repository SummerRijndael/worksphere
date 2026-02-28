import { reactive } from 'vue';

export interface LogEntry {
    id: string;
    timestamp: string;
    level: 'info' | 'warn' | 'error' | 'debug';
    message: string;
    data?: any;
    stack?: string;
}

class LogManager {
    public logs = reactive<LogEntry[]>([]);
    private originalConsole: any = {};
    private isInitialized = false;

    constructor() {
        this.originalConsole = {
            log: console.log,
            info: console.info,
            warn: console.warn,
            error: console.error,
            debug: console.debug
        };
    }

    public init() {
        if (this.isInitialized) return;

        const levels: Array<keyof typeof this.originalConsole> = ['log', 'info', 'warn', 'error', 'debug'];
        
        levels.forEach(level => {
            const mappedLevel: LogEntry['level'] = 
                level === 'log' ? 'info' : 
                level === 'debug' ? 'debug' : 
                level === 'warn' ? 'warn' : 
                level === 'error' ? 'error' : 'info';

            (console as any)[level] = (...args: any[]) => {
                // Call original console (Vite might strip this in build, but we don't care because we capture it below)
                this.originalConsole[level].apply(console, args);
                
                // Capture the log
                this.captureLog(mappedLevel, args);
            };
        });

        this.isInitialized = true;
        console.info("[LogManager] Intercepting console logs for diagnostics...");
    }

    private captureLog(level: LogEntry['level'], args: any[]) {
        const timestamp = new Date().toLocaleTimeString();
        const id = Math.random().toString(36).substring(2, 11);

        // Process message and data
        let message = "";
        let data: any = null;

        if (typeof args[0] === 'string') {
            message = args[0];
            data = args.length > 1 ? (args.length === 2 ? args[1] : args.slice(1)) : null;
        } else {
            message = "[Object Log]";
            data = args.length === 1 ? args[0] : args;
        }

        // Try to capture stack for errors
        let stack: string | undefined;
        if (level === 'error' && data instanceof Error) {
            stack = data.stack;
        }

        this.logs.unshift({
            id,
            timestamp,
            level,
            message,
            data,
            stack
        });

        // Limit logs to prevent memory issues
        if (this.logs.length > 200) {
            this.logs.pop();
        }
    }

    public clear() {
        this.logs.splice(0, this.logs.length);
    }
}

export const logManager = new LogManager();
