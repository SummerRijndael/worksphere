<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import PublicLayout from "@/layouts/PublicLayout.vue";
import { PageLoader, Card, Button } from "@/components/ui";
import {
    Search,
    ChevronRight,
    FileText,
    ChevronDown,
    ChevronUp,
    Book,
    Eye,
    ThumbsUp,
} from "lucide-vue-next";
import axios from "axios";
import DOMPurify from "dompurify";

interface FaqArticle {
    id: string;
    title: string;
    slug: string;
    content?: string;
    views: number;
    helpful_count: number;
}

interface FaqCategory {
    id: string;
    name: string;
    articles: FaqArticle[];
}

const isLoading = ref(true);
const isSearching = ref(false);
const categories = ref<FaqCategory[]>([]);
const searchResults = ref<FaqArticle[]>([]);
const searchQuery = ref("");
const expandedCategories = ref<string[]>([]);
let debounceTimer: ReturnType<typeof setTimeout>;

const fetchFaq = async () => {
    try {
        const response = await axios.get("/api/public/faq");
        categories.value = response.data.data;
        expandedCategories.value = categories.value.map((c) => c.id);
    } catch (error) {
        console.error("Failed to fetch FAQ", error);
    } finally {
        isLoading.value = false;
    }
};

watch(searchQuery, (newQuery) => {
    clearTimeout(debounceTimer);
    if (!newQuery.trim()) {
        searchResults.value = [];
        isSearching.value = false;
        return;
    }

    isSearching.value = true;
    debounceTimer = setTimeout(async () => {
        try {
            const response = await axios.get("/api/public/faq/search", {
                params: { q: newQuery },
            });
            searchResults.value = response.data.data;
        } catch (e) {
            console.error(e);
        } finally {
            isSearching.value = false;
        }
    }, 300);
});

const toggleCategory = (id: string) => {
    if (expandedCategories.value.includes(id)) {
        expandedCategories.value = expandedCategories.value.filter(
            (catId) => catId !== id
        );
    } else {
        expandedCategories.value.push(id);
    }
};

const getPlainExcerpt = (html: string | undefined) => {
    if (!html) return "Learn more...";

    // Sanitize first to remove malicious scripts/styles and their content
    // We explicitly forbid style/script tags to ensure their content is gone
    const cleanHtml = DOMPurify.sanitize(html, {
        FORBID_TAGS: ["style", "script", "svg", "iframe", "object", "embed"],
        keepContent: false, // This isn't a standard option but FORBID_TAGS usually removes content for script/style
    });

    // Use a temporary element to strip remaining tags and decode entities
    const tmp = document.createElement("div");
    tmp.innerHTML = cleanHtml;
    let text = tmp.textContent || tmp.innerText || "";

    // Collapse whitespace
    text = text.replace(/\s+/g, " ").trim();

    return text.length > 100 ? text.substring(0, 100) + "..." : text;
};

onMounted(() => {
    fetchFaq();
});
</script>
<template>
    <PublicLayout>
        <PageLoader :show="isLoading" />

        <!-- Hero Section -->
        <section
            class="relative bg-[var(--surface-primary)] overflow-hidden isolate"
        >
            <!-- Background Decoration -->
            <div
                class="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80"
                aria-hidden="true"
            >
                <div
                    class="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[var(--color-primary-200)] to-[var(--color-primary-400)] opacity-20 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"
                    style="
                        clip-path: polygon(
                            74.1% 44.1%,
                            100% 61.6%,
                            97.5% 26.9%,
                            85.5% 0.1%,
                            80.7% 2%,
                            72.5% 32.5%,
                            60.2% 62.4%,
                            52.4% 68.1%,
                            47.5% 58.3%,
                            45.2% 34.5%,
                            27.5% 76.7%,
                            0.1% 64.9%,
                            17.9% 100%,
                            27.6% 76.8%,
                            76.1% 97.7%,
                            74.1% 44.1%
                        );
                    "
                ></div>
            </div>

            <div
                class="max-w-7xl mx-auto px-6 py-24 sm:py-32 lg:px-8 text-center z-10"
            >
                <div class="mx-auto max-w-2xl">
                    <h1
                        class="text-4xl font-bold tracking-tight text-[var(--text-primary)] sm:text-6xl mb-6"
                    >
                        Advice and answers from the CoreSync Team
                    </h1>
                    <p
                        class="mt-4 text-lg leading-8 text-[var(--text-secondary)] mb-10"
                    >
                        Everything you need to know about navigating the
                        platform, managing projects, and billing.
                    </p>
                    <div class="relative max-w-xl mx-auto">
                        <div
                            class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4"
                        >
                            <Search
                                class="h-5 w-5 text-[var(--text-muted)]"
                                aria-hidden="true"
                            />
                        </div>
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="block w-full rounded-full border-0 py-4 pl-12 pr-4 text-[var(--text-primary)] shadow-sm ring-1 ring-inset ring-[var(--border-strong)] placeholder:text-[var(--text-muted)] focus:ring-2 focus:ring-inset focus:ring-[var(--interactive-primary)] sm:text-sm sm:leading-6 bg-[var(--surface-elevated)]"
                            placeholder="Search for answers..."
                        />
                        <div
                            v-if="isSearching"
                            class="absolute right-4 top-1/2 -translate-y-1/2"
                        >
                            <div
                                class="w-4 h-4 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Fade -->
            <div
                class="absolute inset-x-0 bottom-0 -z-10 h-24 bg-gradient-to-t from-[var(--surface-primary)] sm:h-32"
            ></div>
        </section>

        <!-- Content -->
        <section class="max-w-7xl mx-auto px-6 py-12 lg:px-8">
            <!-- Search Results Mode -->
            <div v-if="searchQuery" class="space-y-8">
                <div
                    class="flex items-center justify-between border-b border-[var(--border-default)] pb-4"
                >
                    <h2
                        class="text-2xl font-bold tracking-tight text-[var(--text-primary)]"
                    >
                        Search Results
                        <span
                            class="text-base font-normal text-[var(--text-secondary)] ml-2"
                            >for "{{ searchQuery }}"</span
                        >
                    </h2>
                    <button
                        @click="searchQuery = ''"
                        class="text-sm text-[var(--interactive-primary)] hover:underline"
                    >
                        Clear search
                    </button>
                </div>

                <div
                    v-if="searchResults.length === 0 && !isSearching"
                    class="text-center py-20"
                >
                    <Book
                        class="mx-auto h-12 w-12 text-[var(--text-muted)] opacity-50 mb-4"
                    />
                    <h3
                        class="mt-2 text-lg font-semibold text-[var(--text-primary)]"
                    >
                        No matching articles
                    </h3>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        Try adjusting your search terms.
                    </p>
                </div>

                <div
                    v-else
                    class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="article in searchResults"
                        :key="article.id"
                        class="relative flex flex-col gap-4 rounded-2xl bg-[var(--surface-elevated)] p-6 ring-1 ring-inset ring-[var(--border-subtle)] hover:ring-[var(--interactive-primary)] hover:shadow-lg transition-all duration-300 cursor-pointer group"
                        @click="$router.push(`/public/faq/${article.slug}`)"
                    >
                        <div class="flex items-center gap-x-4">
                            <div
                                class="flex-shrink-0 flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--surface-tertiary)] border border-[var(--border-default)] group-hover:bg-[var(--interactive-primary)]/10 group-hover:border-[var(--interactive-primary)]/20 transition-colors"
                            >
                                <FileText
                                    class="h-5 w-5 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                />
                            </div>
                            <div class="text-sm leading-6">
                                <p
                                    class="font-semibold text-[var(--text-primary)] line-clamp-2"
                                >
                                    <span class="absolute inset-0"></span>
                                    {{ article.title }}
                                </p>
                            </div>
                        </div>
                        <div class="flex-auto">
                            <p
                                class="text-sm leading-6 text-[var(--text-secondary)] line-clamp-2"
                            >
                                {{ getPlainExcerpt(article.content) }}
                            </p>
                        </div>
                        <div
                            class="flex items-center gap-x-2 text-xs font-medium text-[var(--interactive-primary)] mt-auto pt-2 opacity-0 group-hover:opacity-100 transition-opacity -translate-x-2 group-hover:translate-x-0 duration-300"
                        >
                            Read article <ChevronRight class="h-3 w-3" />
                        </div>
                        
                        <!-- Stats Footer -->
                        <div class="pt-4 mt-auto border-t border-[var(--border-subtle)] flex items-center justify-between text-xs text-[var(--text-tertiary)]">
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-1">
                                    <Eye class="w-3 h-3" />
                                    <span>{{ article.views }}</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <ThumbsUp class="w-3 h-3" />
                                    <span>{{ article.helpful_count }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Default Categories View -->
            <div v-else class="space-y-16">
                <div v-if="categories.length === 0" class="text-center py-20">
                    <Book
                        class="mx-auto h-12 w-12 text-[var(--text-muted)] opacity-50 mb-4"
                    />
                    <h3
                        class="mt-2 text-lg font-semibold text-[var(--text-primary)]"
                    >
                        No articles found
                    </h3>
                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        We couldn't find any resources matching your criteria.
                    </p>
                </div>

                <div
                    v-else
                    v-for="category in categories"
                    :key="category.id"
                    class="space-y-6"
                >
                    <div
                        class="flex items-center justify-between border-b border-[var(--border-default)] pb-4"
                    >
                        <h2
                            class="text-2xl font-bold tracking-tight text-[var(--text-primary)]"
                        >
                            {{ category.name }}
                        </h2>
                        <button
                            @click="toggleCategory(category.id)"
                            class="text-sm font-medium text-[var(--interactive-primary)] hover:text-[var(--interactive-primary-hover)] transition-colors flex items-center gap-1"
                        >
                            {{
                                expandedCategories.includes(category.id)
                                    ? "Hide"
                                    : "Show"
                            }}
                            <component
                                :is="
                                    expandedCategories.includes(category.id)
                                        ? ChevronUp
                                        : ChevronDown
                                "
                                class="h-4 w-4"
                            />
                        </button>
                    </div>

                    <div
                        v-show="expandedCategories.includes(category.id)"
                        class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 animate-fade-in-up"
                    >
                        <div
                            v-for="article in category.articles"
                            :key="article.id"
                            class="relative flex flex-col gap-4 rounded-2xl bg-[var(--surface-elevated)] p-6 ring-1 ring-inset ring-[var(--border-subtle)] hover:ring-[var(--interactive-primary)] hover:shadow-lg transition-all duration-300 cursor-pointer group"
                            @click="$router.push(`/public/faq/${article.slug}`)"
                        >
                            <div class="flex items-center gap-x-4">
                                <div
                                    class="flex-shrink-0 flex h-10 w-10 items-center justify-center rounded-lg bg-[var(--surface-tertiary)] border border-[var(--border-default)] group-hover:bg-[var(--interactive-primary)]/10 group-hover:border-[var(--interactive-primary)]/20 transition-colors"
                                >
                                    <FileText
                                        class="h-5 w-5 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                    />
                                </div>
                                <div class="text-sm leading-6">
                                    <p
                                        class="font-semibold text-[var(--text-primary)] line-clamp-2"
                                    >
                                        <span class="absolute inset-0"></span>
                                        {{ article.title }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex-auto">
                                <p
                                    class="text-sm leading-6 text-[var(--text-secondary)] line-clamp-2"
                                >
                                    {{ getPlainExcerpt(article.content) }}
                                </p>
                            </div>
                            <div
                                class="flex items-center gap-x-2 text-xs font-medium text-[var(--interactive-primary)] mt-auto pt-2 opacity-0 group-hover:opacity-100 transition-opacity -translate-x-2 group-hover:translate-x-0 duration-300"
                            >
                                Read article <ChevronRight class="h-3 w-3" />
                            </div>

                            <!-- Stats Footer -->
                            <div class="pt-4 mt-auto border-t border-[var(--border-subtle)] flex items-center justify-between text-xs text-[var(--text-tertiary)]">
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center gap-1">
                                        <Eye class="w-3 h-3" />
                                        <span>{{ article.views }}</span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <ThumbsUp class="w-3 h-3" />
                                        <span>{{ article.helpful_count }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Still need help? -->
            <div
                class="mt-24 rounded-3xl bg-[var(--surface-secondary)] py-16 px-6 sm:p-16 lg:flex lg:items-center lg:justify-between shadow-sm border border-[var(--border-subtle)]"
            >
                <div class="lg:w-0 lg:flex-1">
                    <h2
                        class="text-3xl font-bold tracking-tight text-[var(--text-primary)]"
                    >
                        Still need help?
                    </h2>
                    <p
                        class="mt-4 max-w-xl text-lg text-[var(--text-secondary)]"
                    >
                        Can't find the answer you're looking for? Our dedicated
                        support team is here to help you with any questions or
                        issues you might have.
                    </p>
                </div>
                <div class="mt-10 lg:mt-0 lg:ml-8 lg:flex-shrink-0">
                    <RouterLink to="/support">
                        <Button size="lg" class="w-full sm:w-auto shadow-md"
                            >Contact Support</Button
                        >
                    </RouterLink>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
