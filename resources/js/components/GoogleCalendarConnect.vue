<template>
    <div class="flex items-center">
        <!-- Connected state -->
        <div
            v-if="isConnected"
            class="group flex items-center gap-1.5 px-3 h-9 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 text-sm font-medium transition-all"
        >
            <CheckCircleIcon class="h-3.5 w-3.5 shrink-0" />
            <span class="hidden sm:inline">Synced</span>
            <span
                v-if="accountEmail"
                class="hidden lg:inline text-xs opacity-60 truncate max-w-[120px]"
            >
                {{ accountEmail }}
            </span>
            <button
                @click="disconnectGoogle"
                class="ml-1 p-1 rounded hover:bg-black/10 dark:hover:bg-white/10 text-green-600/60 hover:text-red-500 transition-colors"
                title="Disconnect Google Calendar"
            >
                <TrashIcon class="h-3 w-3" />
            </button>
        </div>

        <!-- Connect button -->
        <button
            v-else
            type="button"
            @click="connectGoogle"
            :disabled="isLoading"
            class="flex items-center gap-1.5 px-3 h-9 rounded-lg text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) text-sm font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed"
        >
            <LoaderCircle v-if="isLoading" class="h-3.5 w-3.5 animate-spin" />
            <img
                v-else
                src="https://www.svgrepo.com/show/475656/google-color.svg"
                class="h-3.5 w-3.5"
                alt="Google"
            />
            <span>{{ isLoading ? "Connecting..." : "Sync Google" }}</span>
        </button>

        <div v-if="error" class="ml-2 text-xs text-red-500">!</div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { CheckCircleIcon, TrashIcon } from "@heroicons/vue/24/solid";
import { LoaderCircle } from "lucide-vue-next";
import api from "@/lib/api";
import { toast } from "vue-sonner";

const emit = defineEmits(["connected", "disconnected"]);

const isConnected = ref(false);
const isLoading = ref(false);
const accountEmail = ref("");
const error = ref("");
let popupWindow = null;
let popupTimer = null;

// ── Message listener for popup callback ────────────────────────────────────
function handleOAuthMessage(event) {
    if (event.origin !== window.location.origin) return;
    if (!event.data || event.data.type !== "google-calendar-oauth") return;

    clearInterval(popupTimer);

    if (event.data.success) {
        isConnected.value = true;
        isLoading.value = false;
        toast.success("Google Calendar connected!");
        emit("connected");
        // Re-fetch account details to get the email
        fetchStatus();
    } else {
        isLoading.value = false;
        error.value = event.data.error || "connection_failed";
        if (event.data.error !== "access_denied") {
            toast.error("Failed to connect Google Calendar.");
        }
    }
}

onMounted(async () => {
    window.addEventListener("message", handleOAuthMessage);
    await fetchStatus();
});

onUnmounted(() => {
    window.removeEventListener("message", handleOAuthMessage);
    clearInterval(popupTimer);
    if (popupWindow && !popupWindow.closed) popupWindow.close();
});

// ── Status check ────────────────────────────────────────────────────────────
async function fetchStatus() {
    try {
        const { data } = await api.get("/api/user/social-accounts");
        const googleAccount = data.find(
            (acc) =>
                acc.provider === "google" &&
                acc.scopes?.includes(
                    "https://www.googleapis.com/auth/calendar.events",
                ),
        );
        if (googleAccount) {
            isConnected.value = true;
            accountEmail.value = googleAccount.provider_email;
        } else {
            isConnected.value = false;
            accountEmail.value = "";
        }
    } catch (e) {
        console.error("[GoogleCalendarConnect] Status check failed:", e);
    }
}

// ── Open popup window ───────────────────────────────────────────────────────
const connectGoogle = async () => {
    if (isLoading.value) return;
    isLoading.value = true;
    error.value = "";

    try {
        const { data } = await api.get("/api/calendar/oauth/connect");
        const url = data.url;

        const width = 520;
        const height = 660;
        const left = Math.round(
            window.screenX + (window.outerWidth - width) / 2,
        );
        const top = Math.round(
            window.screenY + (window.outerHeight - height) / 2,
        );

        popupWindow = window.open(
            url,
            "GoogleCalendarAuth",
            `width=${width},height=${height},left=${left},top=${top},` +
                `scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no,status=no`,
        );

        if (!popupWindow) {
            // Popup was blocked — fall back to redirect
            toast.error("Popup blocked. Redirecting instead...");
            window.location.href = url;
            return;
        }

        // Poll for popup closure (user manually closed without authorising).
        // Note: while Google's consent page is open, COOP headers block access
        // to popupWindow.closed — we catch that and wait for postMessage instead.
        popupTimer = setInterval(() => {
            try {
                if (popupWindow && popupWindow.closed) {
                    clearInterval(popupTimer);
                    if (isLoading.value) {
                        isLoading.value = false;
                    }
                }
            } catch {
                // COOP policy blocks access while on Google's domain — ignore,
                // success will arrive via postMessage once back on our origin.
            }
        }, 500);
    } catch (e) {
        isLoading.value = false;
        error.value = "Failed to initiate connection.";
        toast.error("Could not start Google connection.");
    }
};

// ── Disconnect ──────────────────────────────────────────────────────────────
const disconnectGoogle = async () => {
    if (!confirm("Disconnect Google Calendar? This will stop syncing events."))
        return;

    isLoading.value = true;
    try {
        await api.delete("/api/calendar/oauth/disconnect");
        toast.success("Google Calendar disconnected.");
        isConnected.value = false;
        accountEmail.value = "";
        emit("disconnected");
    } catch (e) {
        console.error("[GoogleCalendarConnect] Disconnect failed:", e);
        toast.error("Failed to disconnect Google Calendar.");
    } finally {
        isLoading.value = false;
    }
};
</script>
