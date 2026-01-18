<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { Modal, Button, Alert } from '@/components/ui';
import { Shield, TrendingUp, TrendingDown, ArrowRight, LogOut } from 'lucide-vue-next';

const props = defineProps({
    open: Boolean,
    fromRole: String,
    toRole: String,
    action: {
        type: String,
        validator: (v) => ['promoted', 'demoted', 'changed'].includes(v),
    },
});

const emit = defineEmits(['update:open', 'logout']);

const router = useRouter();
const authStore = useAuthStore();

const actionConfig = computed(() => ({
    promoted: {
        title: 'Role Upgraded',
        message: 'Congratulations! Your permissions have been upgraded.',
        icon: TrendingUp,
        iconColor: 'text-green-500',
        bgColor: 'bg-green-500/10',
        alertVariant: 'success',
    },
    demoted: {
        title: 'Role Changed',
        message: 'Your permissions have been adjusted.',
        icon: TrendingDown,
        iconColor: 'text-amber-500',
        bgColor: 'bg-amber-500/10',
        alertVariant: 'warning',
    },
    changed: {
        title: 'Role Changed',
        message: 'Your role has been changed.',
        icon: Shield,
        iconColor: 'text-blue-500',
        bgColor: 'bg-blue-500/10',
        alertVariant: 'info',
    },
})[props.action] || {
    title: 'Role Changed',
    message: 'Your role has been changed.',
    icon: Shield,
    iconColor: 'text-blue-500',
    bgColor: 'bg-blue-500/10',
    alertVariant: 'info',
});

const roleLabels = {
    administrator: 'Administrator',
    project_manager: 'Project Manager',
    operator: 'Operator',
    user: 'User',
};

function getRoleLabel(role) {
    return roleLabels[role] || role;
}

async function handleLogout() {
    emit('update:open', false);
    try {
        await authStore.logout();
    } catch (e) {
        // Ignore logout errors
    }
    router.push({ name: 'login' });
    emit('logout');
}
</script>

<template>
    <Modal
        :open="open"
        @update:open="emit('update:open', $event)"
        title="Role Changed"
        :prevent-close="action === 'demoted'"
    >
        <div class="space-y-6 text-center">
            <!-- Icon -->
            <div
                class="mx-auto w-16 h-16 rounded-full flex items-center justify-center"
                :class="actionConfig.bgColor"
            >
                <component
                    :is="actionConfig.icon"
                    class="w-8 h-8"
                    :class="actionConfig.iconColor"
                />
            </div>

            <!-- Message -->
            <div>
                <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-2">
                    {{ actionConfig.message }}
                </h3>

                <!-- Role transition -->
                <div class="flex items-center justify-center gap-3 text-[var(--text-secondary)]">
                    <span class="px-3 py-1.5 rounded-lg bg-[var(--surface-secondary)] font-medium">
                        {{ getRoleLabel(fromRole) }}
                    </span>
                    <ArrowRight class="w-5 h-5 text-[var(--text-muted)]" />
                    <span class="px-3 py-1.5 rounded-lg bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] font-medium">
                        {{ getRoleLabel(toRole) }}
                    </span>
                </div>
            </div>

            <!-- Alert -->
            <Alert :variant="actionConfig.alertVariant">
                <template v-if="action === 'demoted'">
                    Some features may no longer be accessible. Please log out and log back in for the changes to take effect.
                </template>
                <template v-else>
                    Please log out and log back in for the changes to take effect.
                </template>
            </Alert>

            <!-- Action -->
            <Button fullWidth @click="handleLogout">
                <LogOut class="w-4 h-4" />
                Log Out Now
            </Button>
        </div>
    </Modal>
</template>
