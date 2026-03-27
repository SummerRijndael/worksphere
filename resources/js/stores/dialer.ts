import { defineStore } from "pinia";
import { ref, watch } from "vue";
import api from "@/lib/api";

export type DialerLaunchMode = "popup" | "docked";
type DialerTone = "secondary" | "info" | "warning" | "success" | "danger" | "muted";

interface DialerLiveCall {
    id: string;
    to_number: string;
    contact_name: string | null;
    status: string;
    status_label: string;
    status_tone: DialerTone;
    can_hangup: boolean;
    started_at: string | null;
    duration_seconds: number | null;
}

export const useDialerStore = defineStore("dialer", () => {
    const launchMode = ref<DialerLaunchMode>("popup");
    const isDockedOpen = ref(false);
    const activeCall = ref<DialerLiveCall | null>(null);
    const isRefreshingStatus = ref(false);
    const isHangingUp = ref(false);
    const lastSyncedAt = ref<number | null>(null);
    const isStatusMonitoring = ref(false);
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let storageListenerAttached = false;

    function getPublicId(): string {
        try {
            const authData = localStorage.getItem("worksphere-auth");
            if (!authData) return "guest";

            const parsed = JSON.parse(authData);
            return parsed?.user?.public_id || "guest";
        } catch {
            return "guest";
        }
    }

    function getStorageKey(): string {
        return `worksphere_dialer_mode_${getPublicId()}`;
    }

    function getModeFromRawPreference(raw: string | null): DialerLaunchMode | null {
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw);
            if (
                parsed?.launchMode === "popup" ||
                parsed?.launchMode === "docked"
            ) {
                return parsed.launchMode;
            }
            return null;
        } catch {
            return null;
        }
    }

    function loadPreference(): void {
        if (typeof window === "undefined") return;
        const mode = getModeFromRawPreference(localStorage.getItem(getStorageKey()));
        if (mode) {
            launchMode.value = mode;
        }
    }

    function savePreference(): void {
        if (typeof window === "undefined") return;
        localStorage.setItem(
            getStorageKey(),
            JSON.stringify({
                launchMode: launchMode.value,
            }),
        );
    }

    function setLaunchMode(mode: DialerLaunchMode): void {
        launchMode.value = mode;
        if (mode === "popup") {
            isDockedOpen.value = false;
            stopStatusPolling();
            return;
        }
        startStatusPolling();
    }

    function openPopupWindow(): boolean {
        if (typeof window === "undefined") return false;

        const width = 340;
        const height = 540;
        const left = window.screenX + (window.outerWidth - width) / 2;
        const top = window.screenY + (window.outerHeight - height) / 2;

        const popup = window.open(
            "/dialer",
            "WorkSphereDialer",
            `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,status=no,location=no,toolbar=no,menubar=no`,
        );

        if (!popup) {
            return false;
        }

        popup.focus();
        return true;
    }

    function openDialer(): void {
        if (launchMode.value === "popup") {
            const opened = openPopupWindow();
            if (!opened) {
                launchMode.value = "docked";
                isDockedOpen.value = true;
                startStatusPolling();
            }
            return;
        }

        isDockedOpen.value = true;
        startStatusPolling();
    }

    function closeDocked(): void {
        isDockedOpen.value = false;
    }

    function toggleDocked(): void {
        isDockedOpen.value = !isDockedOpen.value;
        if (isDockedOpen.value && launchMode.value === "docked") {
            startStatusPolling();
        }
    }

    function getPollDelay(): number {
        return activeCall.value?.can_hangup ? 3000 : 12000;
    }

    function clearPollTimer(): void {
        if (!pollTimer) return;
        clearTimeout(pollTimer);
        pollTimer = null;
    }

    function applyBootstrapPayload(payload: any): void {
        const call = payload?.active_call ?? null;
        if (!call || !call.id) {
            activeCall.value = null;
            lastSyncedAt.value = Date.now();
            return;
        }

        activeCall.value = {
            id: String(call.id),
            to_number: String(call.to_number ?? ""),
            contact_name: call.contact_name ?? null,
            status: String(call.status ?? ""),
            status_label: String(call.status_label ?? call.status ?? "Unknown"),
            status_tone: (call.status_tone ?? "secondary") as DialerTone,
            can_hangup: Boolean(call.can_hangup),
            started_at: call.started_at ?? null,
            duration_seconds:
                typeof call.duration_seconds === "number"
                    ? call.duration_seconds
                    : null,
        };
        lastSyncedAt.value = Date.now();
    }

    function syncFromBootstrap(payload: any): void {
        applyBootstrapPayload(payload);
    }

    async function refreshActiveCall(silent = true): Promise<void> {
        if (isRefreshingStatus.value) return;

        isRefreshingStatus.value = true;
        try {
            const response = await api.get("/api/dialer/bootstrap");
            applyBootstrapPayload(response.data?.data ?? null);
        } catch {
            if (!silent) {
                activeCall.value = null;
            }
        } finally {
            isRefreshingStatus.value = false;
        }
    }

    function scheduleStatusPoll(): void {
        clearPollTimer();
        if (!isStatusMonitoring.value) return;
        if (typeof window === "undefined") return;

        pollTimer = window.setTimeout(async () => {
            await refreshActiveCall(true);
            scheduleStatusPoll();
        }, getPollDelay());
    }

    function startStatusPolling(): void {
        if (isStatusMonitoring.value) return;
        isStatusMonitoring.value = true;
        void refreshActiveCall(true).finally(() => {
            scheduleStatusPoll();
        });
    }

    function stopStatusPolling(): void {
        isStatusMonitoring.value = false;
        clearPollTimer();
    }

    function ensureStatusMonitoring(): void {
        if (launchMode.value === "docked") {
            startStatusPolling();
        }
    }

    function handleStorageSync(event: StorageEvent): void {
        if (typeof window === "undefined") return;
        if (event.storageArea !== localStorage) return;
        if (event.key !== getStorageKey()) return;

        const mode = getModeFromRawPreference(event.newValue);
        if (!mode || mode === launchMode.value) {
            return;
        }

        launchMode.value = mode;
        if (mode === "popup") {
            isDockedOpen.value = false;
            stopStatusPolling();
            return;
        }

        isDockedOpen.value = true;
        startStatusPolling();
    }

    async function hangupActiveCall(): Promise<boolean> {
        if (!activeCall.value || !activeCall.value.can_hangup || isHangingUp.value) {
            return false;
        }

        isHangingUp.value = true;
        try {
            await api.post(`/api/dialer/calls/${activeCall.value.id}/hangup`);
            await refreshActiveCall(false);
            return true;
        } catch {
            return false;
        } finally {
            isHangingUp.value = false;
        }
    }

    loadPreference();
    if (typeof window !== "undefined" && !storageListenerAttached) {
        window.addEventListener("storage", handleStorageSync);
        storageListenerAttached = true;
    }

    watch(launchMode, () => {
        savePreference();
    });

    return {
        launchMode,
        isDockedOpen,
        setLaunchMode,
        openDialer,
        closeDocked,
        toggleDocked,
        openPopupWindow,
        activeCall,
        isRefreshingStatus,
        isHangingUp,
        lastSyncedAt,
        isStatusMonitoring,
        ensureStatusMonitoring,
        refreshActiveCall,
        syncFromBootstrap,
        startStatusPolling,
        stopStatusPolling,
        hangupActiveCall,
    };
});
