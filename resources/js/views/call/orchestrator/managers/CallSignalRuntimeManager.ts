export class CallSignalRuntimeManager {
    private readonly pendingSignals: any[] = [];
    private readonly processedSignals = new Set<string>();

    constructor(private readonly maxProcessedSignals = 500) {}

    enqueuePendingSignal(event: any): void {
        this.pendingSignals.push(event);
    }

    drainPendingSignals(): any[] {
        if (this.pendingSignals.length === 0) return [];
        return this.pendingSignals.splice(0, this.pendingSignals.length);
    }

    getPendingCount(): number {
        return this.pendingSignals.length;
    }

    markIfNewSignal(senderId: string, signal: any): boolean {
        const signalId = JSON.stringify(signal) + senderId;
        if (this.processedSignals.has(signalId)) return false;

        this.processedSignals.add(signalId);
        if (this.processedSignals.size > this.maxProcessedSignals) {
            const first = this.processedSignals.values().next().value;
            if (first !== undefined) this.processedSignals.delete(first);
        }
        return true;
    }

    clear(): void {
        this.pendingSignals.length = 0;
        this.processedSignals.clear();
    }
}
