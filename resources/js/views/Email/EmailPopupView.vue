<template>
    <div class="email-popup-container" v-if="email">
        <EmailPreviewContent
            :email="email"
            :is-popup="true"
            :embedded="false"
            @reply="handleAction('reply')"
            @reply-all="handleAction('reply-all')"
            @forward="handleAction('forward')"
            @forward-as-attachment="handleAction('forward-as-attachment')"
        />
    </div>
    <div v-else-if="loading" class="loading-state">
        <div class="spinner"></div>
        <p>Loading email...</p>
    </div>
    <div v-else class="error-state">
        <p>Email not found or access denied.</p>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute } from "vue-router";
import { emailService } from "@/services/email.service";
import EmailPreviewContent from "@/views/Email/components/EmailPreviewContent.vue";
import type { Email } from "@/types/models/email";

const route = useRoute();
const email = ref<Email | null>(null);
const loading = ref(true);

function handleAction(
    action: "reply" | "reply-all" | "forward" | "forward-as-attachment",
) {
    if (window.opener && !window.opener.closed) {
        window.opener.postMessage(
            {
                type: action,
                emailId: email.value?.id,
            },
            window.location.origin,
        );
        window.opener.focus();
    } else {
        alert(
            "Please open the main application window to perform this action.",
        );
    }
}

onMounted(async () => {
    const id = route.params.id as string;
    if (id) {
        try {
            email.value = await emailService.find(id);
        } catch (error) {
            console.error("Failed to load email for popup:", error);
        } finally {
            loading.value = false;
        }
    }
});
</script>

<style scoped>
.email-popup-container {
    height: 100vh;
    width: 100vw;
    background: #ffffff; /* Force light background for popup base */
    overflow: hidden;
}

.loading-state,
.error-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100vh;
    color: #6b7280;
    font-family:
        system-ui,
        -apple-system,
        sans-serif;
}

.spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #3b82f6;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    animation: spin 1s linear infinite;
    margin-bottom: 12px;
}

@keyframes spin {
    0% {
        transform: rotate(0deg);
    }
    100% {
        transform: rotate(360deg);
    }
}
</style>
