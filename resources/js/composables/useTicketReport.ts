import { ref, reactive } from 'vue';
import axios from 'axios';
import { useToast } from '@/composables/useToast';

export interface TicketStats {
    total: number;
    open: number;
    in_progress: number;
    resolved: number;
    closed: number;
    unassigned: number;
    overdue: number;
    sla_breached: number;
    by_priority: Record<string, number>;
}

export interface UserWorkload {
    user_id: number;
    name: string;
    avatar_url: string | null;
    count: number;
    initials: string;
}

export function useTicketReport() {
    const { toast } = useToast();
    const stats = ref<TicketStats | null>(null);
    const workload = ref<UserWorkload[]>([]);
    const loading = ref(false);

    const filters = reactive({
        date_from: '',
        date_to: '',
        search: '',
    });

    const fetchStats = async () => {
        loading.value = true;
        try {
            const { data } = await axios.get('/api/reports/tickets/stats', { params: filters });
            stats.value = data;
        } catch (error: any) {
            console.error('Failed to fetch ticket stats', error);
        } finally {
            loading.value = false;
        }
    };
    
    const fetchWorkload = async () => {
         try {
            const { data } = await axios.get('/api/reports/tickets/workload', { params: filters });
            workload.value = data;
        } catch (error: any) {
            console.error('Failed to fetch workload', error);
        }
    };

    const exportReport = async (exportFilters: any = {}) => {
        try {
            await axios.post('/api/reports/tickets/export', { ...filters, ...exportFilters });
            toast.success('Export started', 'You will be notified when the file is ready.');
        } catch (error: any) {
            console.error('Export failed', error);
            toast.error('Export failed', error.response?.data?.message || 'Please try again later.');
        }
    };
    
    return {
        stats,
        workload,
        loading,
        filters,
        fetchStats,
        fetchWorkload,
        exportReport
    };
}
