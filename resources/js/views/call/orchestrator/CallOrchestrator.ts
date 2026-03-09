import type { CallOrchestratorOptions } from "./types";
import { HeartbeatWorker } from "./workers/HeartbeatWorker";
import { PollingWorker } from "./workers/PollingWorker";

export class CallOrchestrator {
    private readonly heartbeatWorker: HeartbeatWorker;
    private readonly networkPollingWorker: PollingWorker;

    constructor(private readonly options: CallOrchestratorOptions) {
        this.heartbeatWorker = new HeartbeatWorker({
            shouldRun: options.shouldHeartbeat,
            run: options.heartbeat,
            onError: (error) => options.onWarn?.("Heartbeat worker failed", error),
        });

        this.networkPollingWorker = new PollingWorker({
            intervalMs: options.statsIntervalMs ?? 5000,
            run: options.pollNetworkStats,
            onError: (error) => options.onWarn?.("Network polling worker failed", error),
        });
    }

    start(): void {
        this.networkPollingWorker.start();
        this.syncHeartbeat();
    }

    stop(): void {
        this.heartbeatWorker.stop();
        this.networkPollingWorker.stop();
    }

    syncHeartbeat(): void {
        if (this.options.shouldHeartbeat()) {
            this.heartbeatWorker.start();
            return;
        }

        this.heartbeatWorker.stop();
    }
}
