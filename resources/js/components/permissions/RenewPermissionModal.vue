<script setup>
import { ref, computed, watch } from "vue";
import { Calendar, Clock, RefreshCw } from "lucide-vue-next";
import Modal from "../ui/Modal.vue";
import Input from "../ui/Input.vue";
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

const newExpiryDate = ref("");

const minDate = computed(() => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().split("T")[0];
});

const currentExpiry = computed(() => {
    if (!props.override?.expires_at) return "N/A";
    return new Date(props.override.expires_at).toLocaleDateString("en-US", {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
});

const daysUntilExpiry = computed(() => {
    if (!props.override?.expires_at) return null;
    const expiryDate = new Date(props.override.expires_at);
    const now = new Date();
    return Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
});

function handleConfirm() {
    if (!newExpiryDate.value) return;
    emit("confirm", {
        override_id: props.override?.id,
        expires_at: newExpiryDate.value,
    });
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            newExpiryDate.value = "";
        }
    }
);
</script>

<template>
    <Modal
        :open="open"
        title="Renew Permission Override"
        description="Extend the expiration date for this temporary permission"
        size="md"
        @update:open="$emit('update:open', $event)"
    >
        <div class="space-y-4">
            <!-- Current Status -->
            <div
                v-if="override"
                class="p-4 rounded-lg bg-[var(--surface-secondary)] space-y-3"
            >
                <div class="flex items-center gap-2">
                    <RefreshCw class="h-4 w-4 text-[var(--text-muted)]" />
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

                <div class="flex items-center gap-4 text-sm">
                    <div class="flex items-center gap-2">
                        <Calendar class="h-4 w-4 text-[var(--text-muted)]" />
                        <span class="text-[var(--text-secondary)]">
                            Current expiry:
                            <span
                                class="font-medium text-[var(--text-primary)]"
                                >{{ currentExpiry }}</span
                            >
                        </span>
                    </div>
                    <Badge
                        v-if="daysUntilExpiry !== null"
                        :variant="
                            daysUntilExpiry <= 0
                                ? 'error'
                                : daysUntilExpiry <= 7
                                ? 'warning'
                                : 'default'
                        "
                        size="sm"
                    >
                        <Clock class="h-3 w-3" />
                        {{
                            daysUntilExpiry <= 0
                                ? "Expired"
                                : `${daysUntilExpiry} days left`
                        }}
                    </Badge>
                </div>
            </div>

            <!-- New Expiry Date -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    New Expiration Date <span class="text-red-500">*</span>
                </label>
                <Input v-model="newExpiryDate" type="date" :min="minDate" />
                <p class="text-xs text-[var(--text-muted)]">
                    The override will remain active until this date
                </p>
            </div>

            <!-- Quick Options -->
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="days in [7, 14, 30, 90]"
                    :key="days"
                    variant="outline"
                    size="sm"
                    @click="
                        () => {
                            const date = new Date();
                            date.setDate(date.getDate() + days);
                            newExpiryDate = date.toISOString().split('T')[0];
                        }
                    "
                >
                    +{{ days }} days
                </Button>
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
                variant="primary"
                :loading="loading"
                :disabled="!newExpiryDate"
                @click="handleConfirm"
            >
                Renew Override
            </Button>
        </template>
    </Modal>
</template>
