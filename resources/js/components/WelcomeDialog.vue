<script setup>
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Modal, Button } from "@/components/ui"; // Assuming index.js exports these
import { CheckCircle } from "lucide-vue-next"; // Icon

const route = useRoute();
const router = useRouter();
const isOpen = ref(false);

onMounted(() => {
    if (route.query.welcome === "1") {
        isOpen.value = true;
        // Optionally remove the query param so refresh doesn't show it again?
        // router.replace({ query: { ...route.query, welcome: undefined } });
    }
});

function close() {
    isOpen.value = false;
    // Clean URL
    const query = { ...route.query };
    delete query.welcome;
    router.replace({ query });
}
</script>

<template>
    <Modal :show="isOpen" @close="close" title="Welcome aboard!">
        <div class="space-y-4">
            <div
                class="flex flex-col items-center justify-center py-4 text-center"
            >
                <div
                    class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30"
                >
                    <CheckCircle
                        class="h-8 w-8 text-green-600 dark:text-green-400"
                    />
                </div>
                <h3 class="text-xl font-semibold text-[var(--text-primary)]">
                    Registration Successful
                </h3>
                <p class="mt-2 text-[var(--text-secondary)]">
                    Thanks for joining us! We're excited to have you on board.
                </p>
                <p class="mt-2 text-sm text-[var(--text-muted)]">
                    Your account has been created and you have been assigned the
                    default role.
                </p>
            </div>

            <div class="flex justify-end">
                <Button @click="close" full-width> Get Started </Button>
            </div>
        </div>
    </Modal>
</template>
