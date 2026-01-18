<script setup>
import { computed } from 'vue';
import { Check, X } from 'lucide-vue-next';

const props = defineProps({
    password: {
        type: String,
        default: '',
    },
    showRequirements: {
        type: Boolean,
        default: true,
    },
});

const requirements = computed(() => [
    {
        id: 'length',
        label: 'At least 8 characters',
        met: props.password.length >= 8,
    },
    {
        id: 'lowercase',
        label: 'One lowercase letter',
        met: /[a-z]/.test(props.password),
    },
    {
        id: 'uppercase',
        label: 'One uppercase letter',
        met: /[A-Z]/.test(props.password),
    },
    {
        id: 'number',
        label: 'One number',
        met: /[0-9]/.test(props.password),
    },
    {
        id: 'special',
        label: 'One special character',
        met: /[^A-Za-z0-9]/.test(props.password),
    },
]);

const strength = computed(() => {
    if (!props.password) return 0;

    const metCount = requirements.value.filter(r => r.met).length;
    return (metCount / requirements.value.length) * 100;
});

const strengthLevel = computed(() => {
    if (strength.value === 0) return { label: '', color: '', bgColor: '' };
    if (strength.value <= 20) return { label: 'Very Weak', color: 'text-[var(--strength-very-weak)]', bgColor: 'bg-[var(--strength-very-weak)]' };
    if (strength.value <= 40) return { label: 'Weak', color: 'text-[var(--strength-weak)]', bgColor: 'bg-[var(--strength-weak)]' };
    if (strength.value <= 60) return { label: 'Fair', color: 'text-[var(--strength-fair)]', bgColor: 'bg-[var(--strength-fair)]' };
    if (strength.value <= 80) return { label: 'Good', color: 'text-[var(--strength-good)]', bgColor: 'bg-[var(--strength-good)]' };
    return { label: 'Strong', color: 'text-[var(--strength-strong)]', bgColor: 'bg-[var(--strength-strong)]' };
});

const allRequirementsMet = computed(() => requirements.value.every(r => r.met));
</script>

<template>
    <div v-if="password" class="space-y-3">
        <!-- Strength Bar -->
        <div class="space-y-1.5">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-[var(--text-secondary)]">Password strength</span>
                <span :class="['text-xs font-semibold', strengthLevel.color]">
                    {{ strengthLevel.label }}
                </span>
            </div>
            <div class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                <div
                    :class="['h-full rounded-full transition-all duration-300', strengthLevel.bgColor]"
                    :style="{ width: `${strength}%` }"
                />
            </div>
        </div>

        <!-- Requirements List -->
        <div v-if="showRequirements" class="grid grid-cols-2 gap-x-4 gap-y-1.5">
            <div
                v-for="requirement in requirements"
                :key="requirement.id"
                class="flex items-center gap-1.5"
            >
                <div
                    :class="[
                        'flex items-center justify-center w-4 h-4 rounded-full transition-colors',
                        requirement.met
                            ? 'bg-green-100 dark:bg-green-900/30'
                            : 'bg-[var(--surface-tertiary)]'
                    ]"
                >
                    <Check
                        v-if="requirement.met"
                        class="w-2.5 h-2.5 text-green-600 dark:text-green-400"
                    />
                    <X
                        v-else
                        class="w-2.5 h-2.5 text-[var(--text-muted)]"
                    />
                </div>
                <span
                    :class="[
                        'text-xs transition-colors',
                        requirement.met
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-[var(--text-muted)]'
                    ]"
                >
                    {{ requirement.label }}
                </span>
            </div>
        </div>
    </div>
</template>
