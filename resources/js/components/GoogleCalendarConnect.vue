<template>
    <div class="flex items-center">
        <div
            v-if="isConnected"
            class="group flex items-center gap-2 px-3 py-2 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-900/30 text-green-700 dark:text-green-400 text-sm font-medium transition-all"
        >
            <CheckCircleIcon class="h-4 w-4" />
            <span class="hidden sm:inline">Synced</span>
            <span
                v-if="accountEmail"
                class="hidden lg:inline text-xs opacity-75 ml-1"
                >({{ accountEmail }})</span
            >
            
            <!-- Disconnect Button -->
            <button
                @click="disconnectGoogle"
                class="ml-2 p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10 text-green-600/70 hover:text-red-500 transition-colors"
                title="Disconnect from Google Calendar"
            >
                <TrashIcon class="h-3.5 w-3.5" />
            </button>
        </div>

        <button
            v-else
            type="button"
            @click="connectGoogle"
            :disabled="isLoading"
            class="flex items-center gap-2 px-3 py-2 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:bg-[var(--surface-secondary)] text-[var(--text-primary)] text-sm font-medium transition-all shadow-sm"
        >
            <img
                src="https://www.svgrepo.com/show/475656/google-color.svg"
                class="h-4 w-4"
                alt="Google"
            />
            <span v-if="isLoading">Connecting...</span>
            <span v-else>Sync Google</span>
        </button>

        <div v-if="error" class="ml-2 text-xs text-red-600 dark:text-red-400">
            !
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { CheckCircleIcon, TrashIcon } from "@heroicons/vue/24/solid";
import api from "@/lib/api";
import { toast } from "vue-sonner";

const isConnected = ref(false);
const isLoading = ref(false);
const accountEmail = ref("");
const error = ref("");

// Check status on mount
onMounted(async () => {
    // Check for authorization code in URL (Handshake Finalization)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has("code")) {
        console.log(
            "[GoogleCalendarConnect] OAuth Code detected in URL. Initiating handshake..."
        );
        isLoading.value = true;
        try {
            await api.post("/api/calendar/oauth/connect", {
                code: urlParams.get("code"),
            });
            toast.success("Google Calendar connected successfully!");
            isConnected.value = true;
            // Clean URL
            window.history.replaceState({}, "", window.location.pathname);
        } catch (e) {
            console.error("Failed to connect", e);

            // Check if we are actually connected despite the error (e.g. double submission/refresh)
            try {
                const { data } = await api.get("/api/user/social-accounts");
                const googleAccount = data.find(
                    (acc) =>
                        acc.provider === "google" &&
                        acc.scopes?.includes(
                            "https://www.googleapis.com/auth/calendar.events"
                        )
                );

                if (googleAccount) {
                    isConnected.value = true;
                    accountEmail.value = googleAccount.provider_email;
                    // Clean URL as we are already connected
                    window.history.replaceState(
                        {},
                        "",
                        window.location.pathname
                    );
                    return;
                }
            } catch (checkErr) {
                // Check failed, proceed to show error
            }

            error.value = "Failed to complete connection.";
            toast.error("Failed to connect Google Calendar");
        } finally {
            isLoading.value = false;
        }
    }

    // Check current connection status
    try {
        const { data } = await api.get("/api/user/social-accounts");
        const googleAccount = data.find(
            (acc) =>
                acc.provider === "google" &&
                acc.scopes?.includes(
                    "https://www.googleapis.com/auth/calendar.events"
                )
        );

        if (googleAccount) {
            isConnected.value = true;
            accountEmail.value = googleAccount.provider_email;
        }
    } catch (e) {
        console.error("Failed to check status", e);
    }
});

const connectGoogle = async () => {
    isLoading.value = true;
    error.value = "";
    try {
        console.log(
            "[GoogleCalendarConnect] Requesting Redirect URL from Backend..."
        );
        // Step 1: Get Redirect URL
        const { data } = await api.get("/api/calendar/oauth/connect");
        console.log("[GoogleCalendarConnect] Redirect URL received:", data.url);

        // Step 2: Open Popup or Redirect
        window.location.href = data.url;
    } catch (e) {
        error.value = "Failed to initiate connection.";
        isLoading.value = false;
    }
};

const disconnectGoogle = async () => {
    if (!confirm("Are you sure you want to disconnect Google Calendar? This will stop syncing events.")) {
        return;
    }

    isLoading.value = true;
    try {
        await api.delete("/api/calendar/oauth/disconnect");
        toast.success("Google Calendar disconnected.");
        isConnected.value = false;
        accountEmail.value = "";
    } catch (e) {
        console.error("Failed to disconnect", e);
        toast.error("Failed to disconnect Google Calendar.");
    } finally {
        isLoading.value = false;
    }
};
</script>
