export interface HeartbeatWorkerOptions {
    initialDelayMs?: number;
    baseIntervalMs?: number;
    jitterMs?: number;
    shouldRun: () => boolean;
    run: () => Promise<void> | void;
    onError?: (error: unknown) => void;
}

export interface PollingWorkerOptions {
    intervalMs: number;
    run: () => Promise<void> | void;
    onError?: (error: unknown) => void;
}

export interface CallOrchestratorOptions {
    shouldHeartbeat: () => boolean;
    heartbeat: () => Promise<void> | void;
    pollNetworkStats: () => Promise<void> | void;
    onWarn?: (message: string, error?: unknown) => void;
    statsIntervalMs?: number;
}
