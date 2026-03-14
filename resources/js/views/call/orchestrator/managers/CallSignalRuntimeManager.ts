export class CallSignalRuntimeManager {
    private readonly pendingSignals: any[] = [];
    private readonly processedSignals = new Set<string>();
    private readonly maxPendingSignals: number;
    private readonly maxPendingCandidatesPerSender: number;

    constructor(
        private readonly maxProcessedSignals = 500,
        maxPendingSignals = 120,
        maxPendingCandidatesPerSender = 24,
    ) {
        this.maxPendingSignals = maxPendingSignals;
        this.maxPendingCandidatesPerSender = maxPendingCandidatesPerSender;
    }

    private getSenderId(event: any): string {
        return (event?.sender_public_id || event?.senderPublicId || "").toLowerCase();
    }

    private getSignalType(event: any): string {
        return String(event?.signal_data?.type || "").toLowerCase();
    }

    private isCandidateEvent(event: any): boolean {
        const type = this.getSignalType(event);
        return type === "candidate" || type === "ice-candidate";
    }

    private trimPendingCandidatesForSender(senderId: string): void {
        if (!senderId) return;
        let seen = 0;
        for (let i = this.pendingSignals.length - 1; i >= 0; i--) {
            const event = this.pendingSignals[i];
            if (!this.isCandidateEvent(event)) continue;
            if (this.getSenderId(event) !== senderId) continue;
            seen += 1;
            if (seen > this.maxPendingCandidatesPerSender) {
                this.pendingSignals.splice(i, 1);
            }
        }
    }

    private trimPendingToGlobalLimit(): void {
        while (this.pendingSignals.length > this.maxPendingSignals) {
            const candidateIndex = this.pendingSignals.findIndex((event) =>
                this.isCandidateEvent(event),
            );
            if (candidateIndex >= 0) {
                this.pendingSignals.splice(candidateIndex, 1);
                continue;
            }
            this.pendingSignals.shift();
        }
    }

    enqueuePendingSignal(event: any): void {
        this.pendingSignals.push(event);
        if (this.isCandidateEvent(event)) {
            this.trimPendingCandidatesForSender(this.getSenderId(event));
        }
        this.trimPendingToGlobalLimit();
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
