<script setup lang="ts">
import { useRouter } from 'vue-router';
import { Plus } from 'lucide-vue-next';
import { useServices } from '@/composables/useServices';
import ServicePricingCard from '@/components/services/ServicePricingCard.vue';
import { Button } from '@/components/ui';

const router = useRouter();
const { services, deleteService } = useServices();

const handleEdit = (id: string) => {
    router.push(`/services/${id}/edit`);
};

const handleDelete = async (id: string) => {
    if (confirm('Are you sure you want to delete this service plan?')) {
        await deleteService(id);
    }
};

const handleCreate = () => {
    router.push('/services/create');
};
</script>

<template>
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-10 gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-white mb-2">Service Plans</h1>
                <p class="text-zinc-400">Manage your product offerings and pricing tiers.</p>
            </div>
            <Button @click="handleCreate" class="flex items-center gap-2">
                <Plus class="w-4 h-4" />
                Create New Plan
            </Button>
        </div>

        <!-- Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <div v-for="service in services" :key="service.id" class="group h-full">
                <ServicePricingCard 
                    :service="service" 
                    class="h-full"
                    @edit="handleEdit"
                    @delete="handleDelete"
                />
            </div>
            
            <!-- Empty State -->
             <div 
                v-if="services.length === 0" 
                class="col-span-full py-16 text-center border-2 border-dashed border-zinc-800 rounded-xl"
            >
                <h3 class="text-lg font-medium text-white">No service plans found</h3>
                <p class="text-zinc-500 mt-1 mb-4">Get started by creating your first pricing tier.</p>
                <Button variant="outline" @click="handleCreate">Create Plan</Button>
            </div>
        </div>
    </div>
</template>
