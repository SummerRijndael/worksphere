import { ref, computed } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { meetingService } from '@/services/meeting.service';
import { createLogger } from '@/stores/managers/logger';

const log = createLogger('RECORDING');

/**
 * useRecording — PRO meeting recording composable.
 *
 * Architecture:
 *  - Backend controls recording start/stop via Cloudflare RealtimeKit v2 API.
 *  - A virtual bot joins the CF RealtimeKit meeting and records it server-side.
 *  - Frontend only needs to call start/stop and react to signaling events.
 *  - Recording status is broadcast to all participants via `recording-started` /
 *    `recording-stopped` signals so the REC badge shows for everyone.
 *
 * Usage:
 *  const recording = useRecording(meetingId, isHost);
 *  recording.isRecording  // reactive boolean → shows REC badge
 *  recording.startRecording()
 *  recording.stopRecording()
 *
 * Signal integration (call from SignalingManager handlers):
 *  recording.handleRecordingStarted(data)
 *  recording.handleRecordingStopped(data)
 */
export function useRecording(meetingId: string, isHost: () => boolean) {
    const meetingStore = useMeetingStore();
    
    // Core state is now synced with the store
    const isRecording = computed(() => meetingStore.isRecording);
    const recordingId = computed(() => meetingStore.activeRecordingId);
    const formattedDuration = computed(() => meetingStore.formattedRecordingDuration);
    
    const isStarting    = ref(false);
    const isStopping    = ref(false);
    const lastError     = ref<string | null>(null);

    // Timers are now handled globablly by the MeetingStore
    function startTimer() { meetingStore.startRecordingTimer(); }
    function stopTimer()  { meetingStore.stopRecordingTimer(); }

    /**
     * Enabled only when the env toggle is on (via meeting.recording_enabled from API).
     * The store reads this from the MeetingResource.
     */
    const canRecord = computed(() => isHost());

    // ─── Controls ─────────────────────────────────────────────────────────────

    async function startRecording(): Promise<void> {
        if (!canRecord.value || isRecording.value || isStarting.value) return;
        isStarting.value = true;
        lastError.value = null;

        try {
            const result = await meetingService.startRecording(meetingId);
            meetingStore.handleRecordingStarted({
                recording_id: result.recording_id,
                started_by: 'Me'
            });
            log('SYS', 'Recording started', result);
        } catch (e: any) {
            lastError.value = e?.response?.data?.error ?? 'Failed to start recording';
            log('ERROR', 'startRecording failed', e);

            const { toast } = await import('vue-sonner');
            toast.error('Failed to start recording', { description: lastError.value ?? undefined });
        } finally {
            isStarting.value = false;
        }
    }

    async function stopRecording(): Promise<void> {
        if (!isRecording.value || isStopping.value) return;
        isStopping.value = true;
        lastError.value = null;

        try {
            await meetingService.stopRecording(meetingId);
            meetingStore.handleRecordingStopped({
                recording_id: recordingId.value!
            });
            log('SYS', 'Recording stopped');

            const { toast } = await import('vue-sonner');
            toast.success('Recording stopped', { description: 'The recording is being processed by Cloudflare.' });
        } catch (e: any) {
            lastError.value = e?.response?.data?.error ?? 'Failed to stop recording';
            log('ERROR', 'stopRecording failed', e);

            const { toast } = await import('vue-sonner');
            toast.error('Failed to stop recording', { description: lastError.value ?? undefined });
        } finally {
            isStopping.value = false;
        }
    }

    async function forceStopRecording(): Promise<void> {
        if (isStopping.value) return;
        isStopping.value = true;
        try {
            await meetingService.forceStopRecording(meetingId);
            meetingStore.handleRecordingStopped({
                recording_id: recordingId.value || 'forced'
            });
            log('SYS', 'Recording force-stopped');
            const { toast } = await import('vue-sonner');
            toast.success('Recording state reset successfully');
        } catch (e: any) {
            log('ERROR', 'forceStopRecording failed', e);
        } finally {
            isStopping.value = false;
        }
    }

    // ─── Signal Handlers (called from SignalingManager) ───────────────────────

    function handleRecordingStarted(data: { recording_id: string; started_by: string }) {
        log('SIGNAL', 'recording-started received', data);
        // MeetingStore handles the logical state and global notifications now
        meetingStore.handleRecordingStarted(data);
    }

    function handleRecordingStopped(data: { recording_id: string }) {
        log('SIGNAL', 'recording-stopped received', data);
        meetingStore.handleRecordingStopped(data);
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    return {
        isRecording,
        recordingId,
        isStarting,
        isStopping,
        duration: computed(() => meetingStore.recordingDuration),
        formattedDuration,
        lastError,
        canRecord,
        startRecording,
        stopRecording,
        forceStopRecording,
        handleRecordingStarted,
        handleRecordingStopped,
    };
}
