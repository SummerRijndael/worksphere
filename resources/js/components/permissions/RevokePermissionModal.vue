<script setup>
import { ref, watch } from "vue";
import { ShieldOff, AlertTriangle } from "lucide-vue-next";
import Modal from "../ui/Modal.vue";
import Button from "../ui/Button.vue";
import Badge from "../ui/Badge.vue";

const props = defineProps({
    open: Boolean,
    override: {
        type: Object,
        default: null,
    },
    loading: Boolean,
});

const emit = defineEmits(["update:open", "confirm"]);

const reason = ref("");

function handleConfirm() {
    if (reason.value.length < 10) return;
    emit("confirm", {
        override_id: props.override?.id,
        reason: reason.value,
    });
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            reason.value = "";
        }
    }
);
</script>

<template>
    <Modal
        :open="open"
        title="Revoke Permission Override"
        size="md"
        @update:open="$emit('update:open', $event)"
    >
        <div class="space-y-4">
            <!-- Warning -->
            <div
                class="flex items-start gap-3 p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800"
            >
                <AlertTriangle
                    class="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"
                />
                <div class="text-sm text-amber-700 dark:text-amber-300">
                    <p class="font-medium">This action cannot be undone.</p>
                    <p class="mt-1">
                        The permission override will be revoked immediately.
                    </p>
                </div>
            </div>

            <!-- Override Details -->
            <div
                v-if="override"
                class="p-4 rounded-lg bg-[var(--surface-secondary)] space-y-2"
            >
                <div class="flex items-center gap-2">
                    <ShieldOff class="h-4 w-4 text-[var(--text-muted)]" />
                    <span
                        class="font-mono text-sm font-medium text-[var(--text-primary)]"
                    >
                        {{ override.permission }}
                    </span>
                    <Badge
                        :variant="
                            override.type === 'grant' ? 'success' : 'error'
                        "
                        size="sm"
                    >
                        {{ override.type }}
                    </Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">
                    {{ override.reason }}
                </p>
            </div>

            <!-- Reason -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Reason for Revocation <span class="text-red-500">*</span>
                </label>
                <textarea
                    v-model="reason"
                    rows="3"
                    placeholder="Provide a reason for revoking this override (min 10 characters)..."
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
                />
                <p class="text-xs text-[var(--text-muted)]">
                    {{ reason.length }} / 10 characters minimum
                </p>
            </div>
        </div>

        <template #footer>
            <Button
                variant="ghost"
                @click="$emit('update:open', false)"
                :disabled="loading"
            >
                Cancel
            </Button>
            <Button
                variant="danger"
                :loading="loading"
                :disabled="reason.length < 10"
                @click="handleConfirm"
            >
                Revoke Override
            </Button>
        </template>
    </Modal>
</template>
