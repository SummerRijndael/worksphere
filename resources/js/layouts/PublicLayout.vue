<script setup lang="ts">
import PublicHeader from '@/components/public/PublicHeader.vue';
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import PublicFooter from '@/components/public/PublicFooter.vue';
import CookieConsent from '@/components/common/CookieConsent.vue';
import { ScrollToTop } from '@/components/ui';
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-primary)] flex flex-col">
        <PublicHeader />
        <AnnouncementBanner mode="public" />
        
        <main class="flex-1">
            <slot />
        </main>

        <PublicFooter />
        <ScrollToTop />
        <CookieConsent />
    </div>
</template>
