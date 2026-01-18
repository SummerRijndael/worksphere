<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Plus, X, GripVertical } from 'lucide-vue-next';
import { useServices } from '@/composables/useServices';
import { ServiceSchema, defaultServiceValues, type Service } from '@/schemas/ServiceSchema';
import ServicePricingCard from '@/components/services/ServicePricingCard.vue';
import { Button, Input, Checkbox } from '@/components/ui'; // Assuming these exist
import draggable from 'vuedraggable';

const route = useRoute();
const router = useRouter();
const { getService, saveService } = useServices();

const isEditing = computed(() => route.params.id !== undefined);
const isLoading = ref(false);

const form = ref<Service>({ ...defaultServiceValues } as Service);
const errors = ref<Record<string, string>>({});

onMounted(() => {
    if (isEditing.value) {
        const existing = getService(route.params.id as string);
        if (existing) {
            form.value = JSON.parse(JSON.stringify(existing)); // Deep copy
        } else {
            router.push('/services');
        }
    }
});

const featureInput = ref('');

const addFeature = () => {
    if (featureInput.value.trim()) {
        form.value.features.push(featureInput.value.trim());
        featureInput.value = '';
    }
};

const removeFeature = (index: number) => {
    form.value.features.splice(index, 1);
};

const save = async () => {
    // Basic validation
    const result = ServiceSchema.safeParse(form.value);
    if (!result.success) {
         // rough formatting of zod errors
        errors.value = {};
        result.error.issues.forEach(issue => {
            errors.value[issue.path[0]] = issue.message;
        });
        return;
    }
    
    isLoading.value = true;
    await saveService(form.value);
    isLoading.value = false;
    router.push('/services');
};

</script>

<template>
    <div class="container mx-auto px-4 py-8 h-[calc(100vh-theme('spacing.16'))] flex flex-col">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8 shrink-0">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" @click="router.back()">
                    <ArrowLeft class="w-5 h-5" />
                </Button>
                <h1 class="text-2xl font-bold text-white">
                    {{ isEditing ? 'Edit Plan' : 'Create New Plan' }}
                </h1>
            </div>
            <div class="flex items-center gap-3">
                 <Button variant="outline" @click="router.back()">Cancel</Button>
                 <Button @click="save" :disabled="isLoading">
                    {{ isLoading ? 'Saving...' : 'Save Plan' }}
                 </Button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 flex-1 min-h-0">
            <!-- Left: Form -->
            <div class="overflow-y-auto pr-2 pb-10">
                <div class="space-y-6 max-w-lg">
                    
                    <!-- Basic Info -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-medium text-white border-b border-zinc-800 pb-2">Details</h3>
                        
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-300">Plan Name</label>
                            <Input v-model="form.name" placeholder="e.g. Professional" />
                            <span v-if="errors.name" class="text-red-500 text-xs">{{ errors.name }}</span>
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-300">Description</label>
                            <textarea 
                                v-model="form.description" 
                                rows="3"
                                class="w-full rounded-md border border-zinc-800 bg-zinc-950 px-3 py-2 text-sm text-white placeholder:text-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-600"
                                placeholder="Briefly describe who this plan is for..."
                            ></textarea>
                            <span v-if="errors.description" class="text-red-500 text-xs">{{ errors.description }}</span>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="space-y-4 pt-4">
                        <h3 class="text-lg font-medium text-white border-b border-zinc-800 pb-2">Pricing</h3>
                        
                         <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-zinc-300">Price</label>
                                <Input v-model.number="form.price" type="number" step="0.01" />
                            </div>
                            <div class="space-y-2">
                                <label class="text-sm font-medium text-zinc-300">Currency Symbol</label>
                                <Input v-model="form.currency" placeholder="$" />
                            </div>
                        </div>

                         <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-300">Billing Interval / Suffix</label>
                            <Input v-model="form.interval" placeholder="month, year, per user/mo" />
                            <p class="text-xs text-zinc-500">Use 'forever' for one-time payments.</p>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="space-y-4 pt-4">
                        <h3 class="text-lg font-medium text-white border-b border-zinc-800 pb-2">Appearance</h3>
                        
                        <div class="flex items-center justify-between border border-zinc-800 rounded-lg p-3">
                            <div class="flex flex-col">
                                <span class="text-sm font-medium text-white">Most Popular Badge</span>
                                <span class="text-xs text-zinc-500">Highlights this plan with a glow effect</span>
                            </div>
                             <input type="checkbox" v-model="form.is_popular" class="accent-orange-500 h-4 w-4" />
                        </div>

                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-300">Button Text</label>
                            <Input v-model="form.cta_text" placeholder="Get Started" />
                        </div>
                        
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-zinc-300">Theme Color</label>
                            <select v-model="form.color_theme" class="w-full rounded-md border border-zinc-800 bg-zinc-950 px-3 py-2 text-sm text-white">
                                <option value="gray">Standard (Gray)</option>
                                <option value="orange">Featured (Orange)</option>
                                <option value="blue">Blue</option>
                            </select>
                        </div>
                    </div>

                    <!-- Features -->
                    <div class="space-y-4 pt-4">
                        <h3 class="text-lg font-medium text-white border-b border-zinc-800 pb-2">Features List</h3>
                        
                         <div class="flex gap-2">
                            <Input 
                                v-model="featureInput" 
                                @keyup.enter="addFeature" 
                                placeholder="Add a feature (e.g. 'Unlimited Users')" 
                            />
                            <Button @click="addFeature" variant="secondary">Add</Button>
                        </div>

                        <draggable 
                            v-model="form.features" 
                            item-key="index"
                            handle=".handle"
                            class="space-y-2"
                        >
                            <template #item="{ element, index }">
                                <div class="flex items-center gap-2 group bg-zinc-900/50 p-2 rounded-md border border-transparent hover:border-zinc-800">
                                    <GripVertical class="w-4 h-4 text-zinc-600 handle cursor-move" />
                                    <span class="flex-1 text-sm text-zinc-300">{{ element }}</span>
                                    <button @click="removeFeature(index)" class="text-zinc-600 hover:text-red-400">
                                        <X class="w-4 h-4" />
                                    </button>
                                </div>
                            </template>
                        </draggable>
                    </div>

                </div>
            </div>

            <!-- Right: Live Preview -->
            <div class="hidden lg:block bg-black/20 rounded-xl border border-zinc-800/50 p-8">
                <div class="sticky top-8">
                    <div class="text-xs font-mono text-zinc-500 uppercase tracking-wider mb-4 text-center">Live Preview</div>
                    <div class="max-w-sm mx-auto">
                        <ServicePricingCard :service="form" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
