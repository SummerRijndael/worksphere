import { ref, computed } from 'vue';
import api from '@/lib/api';

const roles = ref([]);
const isLoading = ref(false);
const error = ref(null);

export function useRoles() {
    const fetchRoles = async (force = false) => {
        if (roles.value.length > 0 && !force) return;
        
        isLoading.value = true;
        error.value = null;
        try {
            const response = await api.get('/api/roles');
            roles.value = response.data.data;
        } catch (e) {
            console.error('Failed to fetch roles', e);
            error.value = e;
        } finally {
            isLoading.value = false;
        }
    };

    /**
     * Get roles formatted for Select/ComboBox options
     */
    const roleOptions = computed(() => {
        return roles.value.map(role => ({
            value: role.name,
            label: role.label,
            description: role.description,
            color: role.color
        }));
    });

    return {
        roles,
        isLoading,
        error,
        fetchRoles,
        roleOptions
    };
}
