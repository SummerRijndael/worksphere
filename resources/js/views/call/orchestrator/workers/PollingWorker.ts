import type { PollingWorkerOptions } from "../types";

export class PollingWorker {
    private timer: ReturnType<typeof setInterval> | null = null;

    constructor(private readonly options: PollingWorkerOptions) {}

    start(): void {
        this.stop();
        this.timer = setInterval(async () => {
            try {
                await this.options.run();
            } catch (error) {
                this.options.onError?.(error);
            }
        }, this.options.intervalMs);
    }

    stop(): void {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }
}
