import type { HeartbeatWorkerOptions } from "../types";

export class HeartbeatWorker {
    private timeout: ReturnType<typeof setTimeout> | null = null;
    private isActive = false;

    constructor(private readonly options: HeartbeatWorkerOptions) {}

    start(): void {
        this.stop();
        this.isActive = true;
        this.schedule(this.options.initialDelayMs ?? 30000);
    }

    stop(): void {
        this.isActive = false;
        if (this.timeout) {
            clearTimeout(this.timeout);
            this.timeout = null;
        }
    }

    private schedule(delayMs: number): void {
        if (!this.isActive) return;
        this.timeout = setTimeout(() => this.tick(), delayMs);
    }

    private async tick(): Promise<void> {
        if (!this.isActive) return;

        try {
            if (this.options.shouldRun()) {
                await this.options.run();
            }
        } catch (error) {
            this.options.onError?.(error);
        } finally {
            if (!this.isActive) return;
            const base = this.options.baseIntervalMs ?? 60000;
            const jitter = Math.floor(Math.random() * (this.options.jitterMs ?? 10000));
            this.schedule(base + jitter);
        }
    }
}
