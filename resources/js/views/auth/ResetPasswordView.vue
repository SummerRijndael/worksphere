<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { Button, Input } from "@/components/ui";
import { Lock, ArrowLeft } from "lucide-vue-next";
import { toast } from "vue-sonner";
import api from "@/lib/api";
import { animate } from "animejs";

const route = useRoute();
const router = useRouter();
const container = ref(null);

const isLoading = ref(false);
const password = ref("");
const passwordConfirmation = ref("");

const token = route.query.token as string;
const email = route.query.email as string;

const isValid = computed(() => {
    return (
        password.value.length >= 8 &&
        password.value === passwordConfirmation.value
    );
});

onMounted(() => {
    animate(container.value, {
        opacity: [0, 1],
        translateY: [20, 0],
        duration: 800,
        easing: 'easeOutExpo',
    });
});

const submit = async () => {
    if (!isValid.value) return;

    isLoading.value = true;
    try {
        await api.get("/sanctum/csrf-cookie");
        await api.post("/api/reset-password", {
            token,
            email,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });

        toast.success("Password reset successfully!");
        router.push("/auth/login");
    } catch (error: any) {
        toast.error(
            error.response?.data?.message || "Failed to reset password."
        );
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <div ref="container" class="space-y-6">
        <div class="space-y-2">
            <h2 class="text-2xl font-bold text-[var(--text-primary)]">
                Reset Password
            </h2>
            <p class="text-sm text-[var(--text-secondary)]">
                Enter your new password below.
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="space-y-4">
                <Input
                    v-model="password"
                    type="password"
                    label="New Password"
                    placeholder="Min. 8 characters"
                    :icon="Lock"
                    required
                    minlength="8"
                    class="auth-input"
                />

                <Input
                    v-model="passwordConfirmation"
                    type="password"
                    label="Confirm Password"
                    placeholder="Confirm new password"
                    :icon="Lock"
                    required
                    class="auth-input"
                />
            </div>

            <Button
                type="submit"
                full-width
                :disabled="isLoading || !isValid"
                :loading="isLoading"
            >
                Reset Password
            </Button>
        </form>

        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-[var(--border-default)]" />
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-[var(--surface-elevated)] px-2 text-[var(--text-muted)]">
                    Or
                </span>
            </div>
        </div>

        <div class="text-center">
            <router-link
                to="/auth/login"
                class="text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] inline-flex items-center gap-1 transition-colors"
            >
                <ArrowLeft class="h-4 w-4" /> Back to Login
            </router-link>
        </div>
    </div>
</template>
