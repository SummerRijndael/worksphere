<script setup>
import { computed } from 'vue';
import { ComboBox } from '@/components/ui';

const props = defineProps({
    modelValue: {
        type: String,
        default: Intl.DateTimeFormat().resolvedOptions().timeZone,
    },
    disabled: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const timezones = Intl.supportedValuesOf('timeZone');

const options = computed(() => {
    return timezones.map(tz => {
        try {
            // Get offset str e.g., "GMT+8" or similar?
            // Actually Intl doesn't give clean "+08:00" easily without formatting.
            // Let's just list the zones.
            return {
                value: tz,
                label: tz.replace(/_/g, ' ')
            };
        } catch (e) {
            return { value: tz, label: tz };
        }
    });
});

</script>

<template>
    <ComboBox
        :modelValue="modelValue"
        @update:modelValue="$emit('update:modelValue', $event)"
        :options="options"
        placeholder="Select Timezone..."
        search-placeholder="Search timezone..."
        :disabled="disabled"
    />
</template>
