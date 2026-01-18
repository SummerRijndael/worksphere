<script setup>
import { ref, onMounted, watch, computed } from "vue";
import { useRouter } from "vue-router";
import { faqService } from "@/services";
import { debounce } from "lodash";
import { format } from "date-fns";
import {
    SelectFilter,
    Button,
    SearchInput,
    Modal,
    ConfirmPasswordModal,
} from "@/components/ui";
import api from "@/lib/api";
import { storeToRefs } from "pinia";
import { useFaqStore } from "@/stores/faq";
import {
    Plus,
    Edit2,
    Trash2,
    Eye,
    EyeOff,
    X,
    FileText,
    Folder,
    CheckCircle,
    XCircle,
    LayoutList,
    LayoutGrid,
    ChevronLeft,
    ChevronRight,
    ThumbsUp,
    ThumbsDown,
    Search,
    Book,
    RotateCw,
    MessageSquare,
    User,
    ArrowUp,
    ArrowDown,
    Calendar,
    Filter,
} from "lucide-vue-next";

const router = useRouter();
const faqStore = useFaqStore();
const {
    activeTab,
    viewMode,
    showAuthorColumn,
    perPage,
    categorySort,
    articleSort,
    categoryDateRange,
    articleDateRange,
} = storeToRefs(faqStore);

const isLoading = ref(false);
const perPageOptions = [20, 50, 100, 200];

// Stats State
const stats = ref(null);

// Categories State
const categories = ref([]);
const categoryPagination = ref({ current_page: 1, last_page: 1, total: 0 });
const selectedCategories = ref(new Set());
const showCategoryModal = ref(false);
const categoryForm = ref({
    id: null,
    name: "",
    description: "",
    order: 0,
});
const categoryErrors = ref({});
const categorySearch = ref("");
const categoryStatusFilter = ref(""); // 'public', 'private', or ''

// Articles State
const articles = ref([]);
const articlePagination = ref({ current_page: 1, last_page: 1, total: 0 });
const selectedArticles = ref(new Set());
const showArticleModal = ref(false);
const showPreviewModal = ref(false);
const articleForm = ref({
    id: null,
    category_id: "",
    title: "",
    title: "",
    content: "",
    tags: [],
    is_published: false,
});
const tagsInput = ref("");
const addTag = () => {
    const tag = tagsInput.value.trim();
    if (tag && !articleForm.value.tags.includes(tag)) {
        articleForm.value.tags.push(tag);
    }
    tagsInput.value = "";
};
const removeTag = (tag) => {
    articleForm.value.tags = articleForm.value.tags.filter((t) => t !== tag);
};
const articleErrors = ref({});
const articleCategoryFilter = ref("");
const articleStatusFilter = ref(""); // 'published', 'draft', or ''
const showArticleFilters = ref(false);
const articleSearch = ref("");

// Confirmation Modal State
const showConfirmModal = ref(false);
const showPasswordModal = ref(false);
const confirmAction = ref(null); // { type: 'delete'|'publish'|'unpublish', target: 'categories'|'articles', ids: [], single: boolean }
const confirmPassword = ref("");
const confirmReason = ref("");
const confirmLoading = ref(false);
const confirmError = ref("");
const passwordError = ref("");

// Fetch Stats
const fetchStats = async () => {
    try {
        stats.value = await faqService.fetchStats();
    } catch (error) {
        console.error("Failed to fetch stats", error);
    }
};

// Fetch Categories
const fetchCategories = async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            per_page: perPage.value,
            search: categorySearch.value,
            sort_by: categorySort.value.field,
            sort_dir: categorySort.value.direction,
            date_from: categoryDateRange.value.from || undefined,
            sort_dir: categorySort.value.direction,
            date_from: categoryDateRange.value.from || undefined,
            date_to: categoryDateRange.value.to || undefined,
            status: categoryStatusFilter.value || undefined,
        };
        const response = await faqService.fetchCategories(params);
        categories.value = response.data;
        const meta = response.meta || response;
        categoryPagination.value = {
            current_page: meta.current_page,
            last_page: meta.last_page,
            total: meta.total,
        };
        selectedCategories.value.clear();
    } catch (error) {
        console.error("Failed to fetch categories", error);
    } finally {
        isLoading.value = false;
    }
};

// Fetch Articles
const fetchArticles = debounce(async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            per_page: perPage.value,
            category_id: articleCategoryFilter.value,
            status: articleStatusFilter.value || undefined,
            search: articleSearch.value,
            sort_by: articleSort.value.field,
            sort_dir: articleSort.value.direction,
            date_from: articleDateRange.value.from || undefined,
            date_to: articleDateRange.value.to || undefined,
        };
        const response = await faqService.fetchArticles(params);
        articles.value = response.data;
        const meta = response.meta || response;
        articlePagination.value = {
            current_page: meta.current_page,
            last_page: meta.last_page,
            total: meta.total,
        };
        selectedArticles.value.clear();
    } catch (error) {
        console.error("Failed to fetch articles", error);
    } finally {
        isLoading.value = false;
    }
}, 300);

// Selection Logic
const toggleSelection = (id, type) => {
    const set =
        type === "categories"
            ? selectedCategories.value
            : selectedArticles.value;
    if (set.has(id)) set.delete(id);
    else set.add(id);
};

const selectAll = (type) => {
    const set =
        type === "categories"
            ? selectedCategories.value
            : selectedArticles.value;
    const items = type === "categories" ? categories.value : articles.value;

    if (set.size === items.length) {
        set.clear();
    } else {
        items.forEach((item) => set.add(item.id));
    }
};

const isAllSelected = (type) => {
    const set =
        type === "categories"
            ? selectedCategories.value
            : selectedArticles.value;
    const items = type === "categories" ? categories.value : articles.value;
    return items.length > 0 && set.size === items.length;
};

// Bulk Actions - Open confirmation modal
const bulkDelete = (type) => {
    const set =
        type === "categories"
            ? selectedCategories.value
            : selectedArticles.value;
    if (set.size === 0) return;

    confirmAction.value = {
        type: "delete",
        target: type,
        ids: Array.from(set),
        single: false,
    };
    confirmReason.value = "";
    passwordError.value = "";
    showPasswordModal.value = true;
};

// Bulk publish articles - Open confirmation modal
// Bulk publish articles - Open confirmation modal
const bulkPublish = (publish, target = 'articles') => {
    const set = target === 'categories' ? selectedCategories.value : selectedArticles.value;
    if (set.size === 0) return;

    confirmAction.value = {
        type: publish ? "publish" : "unpublish",
        target: target,
        ids: Array.from(set),
        single: false,
    };
    
    if (!publish) {
        confirmReason.value = "";
        passwordError.value = "";
        showPasswordModal.value = true;
    } else {
        confirmReason.value = "";
        showConfirmModal.value = true;
    }
};

// Execute confirmed action
const executeConfirmedAction = async () => {
    if (!confirmAction.value) return;
    await performAction();
};

// Handle password confirmation
const handlePasswordConfirm = async (password, reason) => {
    confirmLoading.value = true;
    passwordError.value = "";

    // Update reason if provided from modal
    if (reason) {
        confirmReason.value = reason;
    }

    try {
        // Verify password with server
        await api.post("/api/user/confirm-password", { password });

        // Password verified, perform the action
        await performAction();
        showPasswordModal.value = false;
    } catch (error) {
        passwordError.value =
            error.response?.data?.errors?.password?.[0] ||
            error.response?.data?.message ||
            "Password verification failed";
        confirmLoading.value = false;
    }
};

// Perform the actual action after all confirmations
const performAction = async () => {
    if (!confirmAction.value) return;

    const { type, target, ids } = confirmAction.value;
    confirmLoading.value = true;
    confirmError.value = "";

    try {
        if (type === "delete") {
            const deleteMethod =
                target === "categories"
                    ? faqService.deleteCategory.bind(faqService)
                    : faqService.deleteArticle.bind(faqService);

            // Pass the reason to the delete method
            await Promise.all(ids.map((id) => deleteMethod(id, confirmReason.value)));

            if (target === "categories") {
                selectedCategories.value.clear();
                fetchCategories(categoryPagination.value.current_page);
            } else {
                selectedArticles.value.clear();
                fetchArticles(articlePagination.value.current_page);
            }
        } else if (type === "publish") {
            await faqService.bulkPublish(ids, true);
            selectedArticles.value.clear();
            fetchArticles(articlePagination.value.current_page);
            fetchArticles(articlePagination.value.current_page);
        } else if (type === "unpublish") {
             if (target === 'categories') {
                await faqService.bulkPublishCategories(ids, false, confirmReason.value);
                selectedCategories.value.clear();
                fetchCategories(categoryPagination.value.current_page);
             } else {
                await faqService.bulkPublish(ids, false, confirmReason.value);
                selectedArticles.value.clear();
                fetchArticles(articlePagination.value.current_page);
             }
        } else if (type === 'save_unpublish') {
            // Handle intercepted save with unpublish
            const data = { ...confirmAction.value.data, reason: confirmReason.value };
            if (target === 'categories') {
                await faqService.updateCategory(ids[0], data);
                showCategoryModal.value = false;
                fetchCategories(categoryPagination.value.current_page);
            }
        } else if (type === 'publish' && target === 'categories') {
            await faqService.bulkPublishCategories(ids, true);
            selectedCategories.value.clear();
            fetchCategories(categoryPagination.value.current_page);
        }

        fetchStats();
        showConfirmModal.value = false;
        showPasswordModal.value = false;
        confirmAction.value = null;
        confirmReason.value = "";
    } catch (error) {
        console.error(`${type} failed`, error);
        confirmError.value =
            error.response?.data?.message || `Failed to ${type}`;
    } finally {
        confirmLoading.value = false;
    }
};

// Category CRUD
const openCategoryModal = (category = null) => {
    categoryErrors.value = {};
    if (category) {
        // Find if this is a real category from list to get full data, currently just copying
        categoryForm.value = { ...category };
    } else {
        categoryForm.value = {
            id: null,
            name: "",
            description: "",
            order: 0,
            is_public: true,
        };
    }
    showCategoryModal.value = true;
};

const saveCategory = async () => {
    try {
        if (categoryForm.value.id) {
            await faqService.updateCategory(
                categoryForm.value.id,
                categoryForm.value,
            );
        } else {
            await faqService.createCategory(categoryForm.value);
        }
        showCategoryModal.value = false;
        fetchCategories(categoryPagination.value.current_page);
        fetchStats();
    } catch (error) {
        if (error.response?.data?.errors) {
            categoryErrors.value = error.response.data.errors;
        }
    }
};

const deleteCategory = (id) => {
    confirmAction.value = {
        type: "delete",
        target: "categories",
        ids: [id],
        single: true,
    };
    confirmReason.value = "";
    passwordError.value = "";
    showPasswordModal.value = true;
};

// Article Navigation
const openArticleEditor = (article = null) => {
    if (article) {
        router.push({
            name: "admin.faq.edit",
            params: { id: article.public_id || article.id },
        });
    } else {
        router.push({ name: "admin.faq.create" });
    }
};

// Removed saveArticle function as it is now handled in the editor page

const deleteArticle = (id) => {
    confirmAction.value = {
        type: "delete",
        target: "articles",
        ids: [id],
        single: true,
    };
    confirmReason.value = "";
    passwordError.value = "";
    showPasswordModal.value = true;
};

// Watchers
const fetchData = async () => {
    if (activeTab.value === "categories") {
        await fetchCategories();
    } else {
        await fetchArticles();
    }
};

watch(activeTab, () => {
    selectedCategories.value.clear();
    selectedArticles.value.clear();
    fetchData();
});

watch(perPage, () => {
    if (activeTab.value === "categories") fetchCategories(1);
    else fetchArticles(1);
});

watch(articleCategoryFilter, () => fetchArticles(1));
watch(articleStatusFilter, () => fetchArticles(1));
watch(articleSearch, () => fetchArticles(1));
watch(
    categorySearch,
    debounce(() => fetchCategories(1), 300),
);
watch(categoryStatusFilter, () => fetchCategories(1));

watch(categorySort, () => fetchCategories(1), { deep: true });
watch(articleSort, () => fetchArticles(1), { deep: true });
watch(categoryDateRange, () => fetchCategories(1), { deep: true });
watch(articleDateRange, () => fetchArticles(1), { deep: true });

onMounted(() => {
    fetchStats();
    if (activeTab.value === "categories") fetchCategories();
    else fetchArticles();
});

const calculateHelpfulPercentage = (item) => {
    const total = (item.helpful_count || 0) + (item.unhelpful_count || 0);
    if (!total) return 0;
    return Math.round((item.helpful_count / total) * 100);
};

const stripHtml = (html) => {
    if (!html) return "";
    const tmp = document.createElement("DIV");
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || "";
};
</script>

<template>
    <div class="p-8 max-w-[1600px] mx-auto space-y-8">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div>
                <h1
                    class="text-3xl font-bold tracking-tight text-[var(--text-primary)]"
                >
                    FAQ Management
                </h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Manage knowledge base categories and article content.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    @click="
                        activeTab === 'categories'
                            ? openCategoryModal()
                            : openArticleEditor()
                    "
                    class="btn btn-primary shadow-lg shadow-blue-500/20"
                >
                    <Plus class="w-4 h-4 mr-2" />
                    New
                    {{ activeTab === "categories" ? "Category" : "Article" }}
                </button>
            </div>
        </div>

        <!-- Stats Overview (Clean) -->
        <div
            v-if="stats"
            class="grid grid-cols-2 md:grid-cols-4 gap-6 animate-fade-in-up"
        >
            <div
                class="flex items-start gap-4 p-4 rounded-xl bg-[var(--surface-elevated)] shadow-sm border border-[var(--border-subtle)]"
            >
                <div
                    class="p-3 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400"
                >
                    <FileText class="w-6 h-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-[var(--text-secondary)]">
                        Total Articles
                    </p>
                    <h3
                        class="text-2xl font-bold text-[var(--text-primary)] mt-1"
                    >
                        {{ stats.total_articles }}
                    </h3>
                </div>
            </div>

            <div
                class="flex items-start gap-4 p-4 rounded-xl bg-[var(--surface-elevated)] shadow-sm border border-[var(--border-subtle)]"
            >
                <div
                    class="p-3 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
                >
                    <Eye class="w-6 h-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-[var(--text-secondary)]">
                        Total Views
                    </p>
                    <h3
                        class="text-2xl font-bold text-[var(--text-primary)] mt-1"
                    >
                        {{ stats.total_views }}
                    </h3>
                </div>
            </div>

            <div
                class="flex items-start gap-4 p-4 rounded-xl bg-[var(--surface-elevated)] shadow-sm border border-[var(--border-subtle)]"
            >
                <div
                    class="p-3 rounded-lg bg-green-500/10 text-green-600 dark:text-green-400"
                >
                    <ThumbsUp class="w-6 h-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-[var(--text-secondary)]">
                        Helpful Votes
                    </p>
                    <div class="flex items-baseline gap-2 mt-1">
                        <h3
                            class="text-2xl font-bold text-[var(--text-primary)]"
                        >
                            {{ stats.helpful_rate?.helper || 0 }}
                        </h3>
                        <span class="text-xs text-[var(--text-muted)]"
                            >/ {{ stats.helpful_rate?.total || 0 }}</span
                        >
                    </div>
                </div>
            </div>

            <div
                class="flex items-start gap-4 p-4 rounded-xl bg-[var(--surface-elevated)] shadow-sm border border-[var(--border-subtle)]"
            >
                <div
                    class="p-3 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"
                >
                    <CheckCircle class="w-6 h-6" />
                </div>
                <div>
                    <p class="text-sm font-medium text-[var(--text-secondary)]">
                        Satisfaction
                    </p>
                    <h3
                        class="text-2xl font-bold text-[var(--text-primary)] mt-1"
                    >
                        {{
                            stats.helpful_rate?.total
                                ? Math.round(
                                      (stats.helpful_rate.helper /
                                          stats.helpful_rate.total) *
                                          100,
                                  )
                                : 0
                        }}%
                    </h3>
                </div>
            </div>
        </div>

        <!-- Toolbar & Filters -->
        <div class="flex flex-col space-y-4">
            <!-- Tabs -->
            <div class="border-b border-[var(--border-default)]">
                <nav class="flex gap-4" aria-label="Tabs">
                    <button
                        @click="activeTab = 'categories'"
                        :class="[
                            'px-1 py-3 text-sm font-medium border-b-2 transition-colors',
                            activeTab === 'categories'
                                ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]',
                        ]"
                    >
                        Categories
                    </button>
                    <button
                        @click="activeTab = 'articles'"
                        :class="[
                            'px-1 py-3 text-sm font-medium border-b-2 transition-colors',
                            activeTab === 'articles'
                                ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]',
                        ]"
                    >
                        Articles
                    </button>
                </nav>
            </div>

            <!-- Controls Row -->
            <div
                class="flex flex-col xl:flex-row gap-4 items-start xl:items-center justify-between"
            >
                <div
                    class="flex-1 flex flex-col sm:flex-row gap-3 w-full xl:w-auto"
                >
                    <!-- Refresh Button -->
                    <button
                        @click="fetchData"
                        :disabled="isLoading"
                        class="p-2.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        title="Refresh List"
                    >
                        <RotateCw
                            class="w-4 h-4"
                            :class="{ 'animate-spin': isLoading }"
                        />
                    </button>

                    <!-- Category Filters (Only visible on Categories tab) -->
                    <div
                        v-if="activeTab === 'categories'"
                        class="flex-1 flex flex-wrap gap-3 animate-fade-in w-full sm:w-auto items-center"
                    >
                        <div class="flex-1 sm:max-w-xs">
                            <SearchInput
                                v-model="categorySearch"
                                placeholder="Search categories..."
                                class="w-full"
                            />
                        </div>
                        <!-- Date Range Filter -->
                        <div
                            class="flex items-center gap-2 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg px-3 py-1.5"
                        >
                            <Calendar
                                class="w-4 h-4 text-[var(--text-tertiary)]"
                            />
                            <input
                                type="date"
                                v-model="categoryDateRange.from"
                                class="bg-transparent text-sm text-[var(--text-primary)] outline-none w-28"
                                title="From Date"
                            />
                            <span class="text-[var(--text-muted)]">-</span>
                            <input
                                type="date"
                                v-model="categoryDateRange.to"
                                class="bg-transparent text-sm text-[var(--text-primary)] outline-none w-28"
                                title="To Date"
                            />
                            <button
                                v-if="
                                    categoryDateRange.from ||
                                    categoryDateRange.to
                                "
                                @click="faqStore.clearDateRange('categories')"
                                class="p-1 hover:bg-[var(--surface-tertiary)] rounded text-[var(--text-secondary)] transition-colors"
                                title="Clear Dates"
                            >
                                <X class="w-3.5 h-3.5" />
                            </button>
                        </div>

                        <!-- Status Filter -->
                        <div class="w-32">
                           <SelectFilter
                               v-model="categoryStatusFilter"
                               :placeholder="'Status'"
                               :options="[
                                   { label: 'All Status', value: '' },
                                   { label: 'Public', value: 'public' },
                                   { label: 'Private', value: 'private' },
                               ]"
                           />
                        </div>
                    </div>


                    <!-- Article Filters (Only visible on Articles tab) -->
                    <div
                        v-if="activeTab === 'articles'"
                        class="flex-1 flex gap-3 animate-fade-in w-full sm:w-auto items-center"
                    >
                        <!-- Search Input (Expanded) -->
                        <div class="flex-1 sm:min-w-[240px]">
                            <SearchInput
                                v-model="articleSearch"
                                placeholder="Search articles..."
                                class="w-full"
                            />
                        </div>

                        <!-- Filter Toggle Button -->
                         <button
                            @click="showArticleFilters = !showArticleFilters"
                            :class="[
                                'p-2.5 rounded-lg border transition-all duration-200',
                                showArticleFilters
                                    ? 'bg-[var(--surface-elevated)] border-[var(--interactive-primary)] text-[var(--interactive-primary)] shadow-sm'
                                    : 'bg-[var(--surface-elevated)] border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]'
                            ]"
                            title="Toggle Filters"
                        >
                            <Filter class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Bulk Actions -->
                    <div
                        v-if="
                            (activeTab === 'categories' &&
                                selectedCategories.size > 0) ||
                            (activeTab === 'articles' &&
                                selectedArticles.size > 0)
                        "
                        class="flex items-center gap-2 animate-fade-in"
                    >
                        <div
                            class="h-8 w-px bg-[var(--border-default)] hidden sm:block mx-1"
                        ></div>
                        <!-- Publish/Unpublish -->
                        <template v-if="activeTab === 'articles' || activeTab === 'categories'">
                            <Button
                                variant="primary"
                                size="sm"
                                class="h-9"
                                @click="bulkPublish(true, activeTab)"
                            >
                                <Eye class="w-4 h-4 mr-1.5" />
                                Publish ({{ activeTab === 'categories' ? selectedCategories.size : selectedArticles.size }})
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                class="h-9"
                                @click="bulkPublish(false, activeTab)"
                            >
                                <EyeOff class="w-4 h-4 mr-1.5" />
                                Unpublish
                            </Button>
                        </template>
                        <Button
                            variant="danger"
                            size="sm"
                            class="h-9"
                            @click="bulkDelete(activeTab)"
                        >
                            <Trash2 class="w-4 h-4 mr-1.5" />
                            Delete ({{
                                activeTab === "categories"
                                    ? selectedCategories.size
                                    : selectedArticles.size
                            }})
                        </Button>
                    </div>
                </div>

                <!-- View Controls -->
                <div class="flex items-center gap-3 ml-auto">
                    <div
                        class="flex items-center gap-1 bg-[var(--surface-elevated)] rounded-lg p-1 border border-[var(--border-default)]"
                    >
                        <button
                            @click="viewMode = 'list'"
                            :class="{
                                'bg-[var(--surface-secondary)] text-[var(--text-primary)] shadow-sm':
                                    viewMode === 'list',
                                'text-[var(--text-secondary)] hover:text-[var(--text-primary)]':
                                    viewMode !== 'list',
                            }"
                            class="p-1.5 rounded-md transition-all"
                            title="List View"
                        >
                            <LayoutList class="w-4 h-4" />
                        </button>
                        <button
                            @click="viewMode = 'grid'"
                            :class="{
                                'bg-[var(--surface-secondary)] text-[var(--text-primary)] shadow-sm':
                                    viewMode === 'grid',
                                'text-[var(--text-secondary)] hover:text-[var(--text-primary)]':
                                    viewMode !== 'grid',
                            }"
                            class="p-1.5 rounded-md transition-all"
                            title="Grid View"
                        >
                            <LayoutGrid class="w-4 h-4" />
                        </button>
                    </div>

                    <button
                        @click="showAuthorColumn = !showAuthorColumn"
                        :class="[
                            'h-9 w-9 flex items-center justify-center rounded-lg border transition-colors',
                            showAuthorColumn
                                ? 'bg-[var(--surface-elevated)] border-[var(--interactive-primary)] text-[var(--interactive-primary)] shadow-sm'
                                : 'bg-[var(--surface-elevated)] border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]',
                        ]"
                        title="Show/Hide Author"
                    >
                        <User class="w-4 h-4" />
                    </button>

                    <div class="w-24">
                        <select
                            v-model="perPage"
                            class="h-9 w-full rounded-md border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm px-3 focus:ring-1 focus:ring-[var(--interactive-primary)] outline-none"
                        >
                            <option
                                v-for="opt in perPageOptions"
                                :key="opt"
                                :value="opt"
                            >
                                {{ opt }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Collapsible Filters Row -->
            <div
                v-if="activeTab === 'articles' && showArticleFilters"
                class="mt-4 pt-4 border-t border-[var(--border-default)] flex flex-wrap gap-4 animate-fade-in"
            >
                <div class="w-full sm:w-64">
                    <SelectFilter
                        v-model="articleCategoryFilter"
                        :options="
                            categories.map((c) => ({
                                value: c.id,
                                label: c.name,
                            }))
                        "
                        placeholder="Filter by Category"
                        class="w-full bg-[var(--surface-elevated)]"
                    />
                </div>
                <div class="w-full sm:w-48">
                    <SelectFilter
                        v-model="articleStatusFilter"
                        :options="[
                            { value: 'published', label: 'Published' },
                            { value: 'draft', label: 'Draft' },
                        ]"
                        placeholder="All Status"
                        class="w-full bg-[var(--surface-elevated)]"
                    />
                </div>
                <!-- Date Range Filter -->
                <div
                    class="flex items-center gap-2 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg px-3 py-1.5"
                >
                    <Calendar class="w-4 h-4 text-[var(--text-tertiary)]" />
                    <input
                        type="date"
                        v-model="articleDateRange.from"
                        class="bg-transparent text-sm text-[var(--text-primary)] outline-none w-28"
                        title="From Date"
                    />
                    <span class="text-[var(--text-muted)]">-</span>
                    <input
                        type="date"
                        v-model="articleDateRange.to"
                        class="bg-transparent text-sm text-[var(--text-primary)] outline-none w-28"
                        title="To Date"
                    />
                    <button
                        v-if="articleDateRange.from || articleDateRange.to"
                        @click="faqStore.clearDateRange('articles')"
                        class="p-1 hover:bg-[var(--surface-tertiary)] rounded text-[var(--text-secondary)] transition-colors"
                        title="Clear Dates"
                    >
                        <X class="w-3.5 h-3.5" />
                    </button>
                </div>

                <!-- Clear All Filters -->
                 <button
                    v-if="articleCategoryFilter || articleStatusFilter || articleDateRange.from || articleDateRange.to"
                    @click="
                        articleCategoryFilter = '';
                        articleStatusFilter = '';
                        faqStore.clearDateRange('articles');
                    "
                    class="text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] underline decoration-dotted underline-offset-4 ml-auto"
                >
                    Clear All Filters
                </button>
            </div>
        </div>

        <!-- Content Area -->
        <div>
            <!-- CATEGORIES TAB -->
            <template v-if="activeTab === 'categories'">
                <Transition name="fade" mode="out-in">
                    <!-- LIST VIEW -->
                    <div
                        v-if="viewMode === 'list'"
                        key="list"
                        class="min-h-[calc(100vh-450px)] bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm overflow-hidden flex flex-col justify-between"
                    >
                        <div class="overflow-x-auto flex-1">
                            <table
                                class="w-full text-left text-sm relative border-collapse"
                            >
                                <thead
                                    class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] font-semibold text-xs uppercase tracking-wider sticky top-0 z-10 border-b border-[var(--border-default)]"
                                >
                                    <tr>
                                        <th class="w-12 px-6 py-4">
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    isAllSelected('categories')
                                                "
                                                @change="
                                                    selectAll('categories')
                                                "
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                            />
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'order',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Order
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'order'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'name',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Name
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'name'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'slug',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Slug
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'slug'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            v-if="showAuthorColumn"
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'author',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Author
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'author'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'is_public',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Status
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'is_public'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'articles_count',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Articles
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'articles_count'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'total_views',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Engagement
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'total_views'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'categories',
                                                    'created_at',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Created
                                                <span
                                                    v-if="
                                                        categorySort.field ===
                                                        'created_at'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            categorySort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th class="px-6 py-4 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="h-full divide-y divide-[var(--border-strong)]"
                                >
                                    <tr v-if="categories.length === 0">
                                        <td
                                            :colspan="showAuthorColumn ? 10 : 9"
                                            class="px-6 h-96 text-center text-[var(--text-muted)] border-0"
                                        >
                                            <div
                                                class="flex flex-col items-center gap-3"
                                            >
                                                <div
                                                    class="p-4 rounded-full bg-[var(--surface-secondary)]"
                                                >
                                                    <Folder
                                                        class="w-8 h-8 opacity-40"
                                                    />
                                                </div>
                                                <span class="font-medium"
                                                    >No categories found</span
                                                >
                                            </div>
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="cat in categories"
                                        :key="cat.id"
                                        class="group hover:bg-[var(--surface-secondary)] transition-colors"
                                    >
                                        <td class="px-6 py-4">
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    selectedCategories.has(
                                                        cat.id,
                                                    )
                                                "
                                                @change="
                                                    toggleSelection(
                                                        cat.id,
                                                        'categories',
                                                    )
                                                "
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                            />
                                        </td>
                                        <td
                                            class="px-6 py-4 font-mono text-[var(--text-tertiary)] text-xs"
                                        >
                                            {{ cat.order }}
                                        </td>
                                        <td
                                            class="px-6 py-4 font-medium text-[var(--text-primary)]"
                                        >
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <div
                                                    class="p-1.5 rounded bg-blue-500/10 text-blue-600 dark:text-blue-400"
                                                >
                                                    <Folder class="w-4 h-4" />
                                                </div>
                                                {{ cat.name }}
                                            </div>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-[var(--text-secondary)] font-mono text-xs"
                                        >
                                            {{ cat.slug }}
                                        </td>
                                        <td
                                            v-if="showAuthorColumn"
                                            class="px-6 py-4"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <img
                                                    v-if="cat.author?.avatar"
                                                    :src="cat.author.avatar"
                                                    class="w-6 h-6 rounded-full object-cover"
                                                />
                                                <div
                                                    v-else
                                                    class="w-6 h-6 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-xs font-medium text-[var(--text-secondary)]"
                                                >
                                                    {{
                                                        cat.author?.name?.charAt(
                                                            0,
                                                        ) || "?"
                                                    }}
                                                </div>
                                                <span
                                                    class="text-sm text-[var(--text-secondary)]"
                                                    >{{
                                                        cat.author?.name ||
                                                        "Unknown"
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span
                                                :class="
                                                    cat.is_public
                                                        ? 'bg-green-500/10 text-green-700 dark:text-green-400 border-transparent'
                                                        : 'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-transparent'
                                                "
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full"
                                            >
                                                <span
                                                    class="w-1.5 h-1.5 rounded-full bg-current"
                                                ></span>
                                                {{
                                                    cat.is_public
                                                        ? "Public"
                                                        : "Hidden"
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div
                                                class="flex items-center gap-1.5 text-sm text-[var(--text-secondary)]"
                                            >
                                                <FileText
                                                    class="w-4 h-4 text-[var(--text-muted)]"
                                                />
                                                <span class="font-medium">{{
                                                    cat.articles_count || 0
                                                }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div
                                                class="flex items-center gap-4 text-xs"
                                            >
                                                <div
                                                    class="flex items-center gap-1.5 text-[var(--text-secondary)]"
                                                    title="Total Views"
                                                >
                                                    <Eye class="w-3.5 h-3.5" />
                                                    <span class="font-medium">{{
                                                        cat.total_views || 0
                                                    }}</span>
                                                </div>
                                                <div
                                                    class="flex items-center gap-3"
                                                >
                                                    <span
                                                        class="flex items-center gap-1 text-green-600 dark:text-green-400 font-medium"
                                                        title="Helpful"
                                                    >
                                                        <ThumbsUp
                                                            class="w-3.5 h-3.5"
                                                        />
                                                        {{
                                                            cat.total_helpful ||
                                                            0
                                                        }}
                                                    </span>
                                                    <span
                                                        class="flex items-center gap-1 text-red-600 dark:text-red-400 font-medium"
                                                        title="Unhelpful"
                                                    >
                                                        <ThumbsDown
                                                            class="w-3.5 h-3.5"
                                                        />
                                                        {{
                                                            cat.total_unhelpful ||
                                                            0
                                                        }}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-xs text-[var(--text-secondary)]"
                                        >
                                            {{
                                                cat.created_at
                                                    ? format(
                                                          new Date(
                                                              cat.created_at,
                                                          ),
                                                          "MMM d, yyyy",
                                                      )
                                                    : "-"
                                            }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex justify-end gap-1">
                                                <button
                                                    @click="
                                                        openCategoryModal(cat)
                                                    "
                                                    class="p-2 hover:bg-[var(--surface-tertiary)] rounded-full text-[var(--text-secondary)] transition-colors"
                                                    title="Edit"
                                                >
                                                    <Edit2 class="w-4 h-4" />
                                                </button>
                                                <button
                                                    @click="
                                                        deleteCategory(cat.id)
                                                    "
                                                    class="p-2 hover:bg-red-50 hover:text-red-500 rounded-full text-[var(--text-secondary)] transition-colors"
                                                    title="Delete"
                                                >
                                                    <Trash2 class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Category Pagination -->
                        <div
                            v-if="categoryPagination.total > 0"
                            class="flex items-center justify-between px-6 py-4 border-t border-[var(--border-default)] bg-[var(--surface-elevated)]"
                        >
                            <p class="text-xs text-[var(--text-secondary)]">
                                Showing
                                {{
                                    (categoryPagination.current_page - 1) *
                                        perPage +
                                    1
                                }}
                                -
                                {{
                                    Math.min(
                                        categoryPagination.current_page *
                                            perPage,
                                        categoryPagination.total,
                                    )
                                }}
                                of {{ categoryPagination.total }}
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    :disabled="
                                        categoryPagination.current_page === 1
                                    "
                                    @click="
                                        fetchCategories(
                                            categoryPagination.current_page - 1,
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    class="h-8"
                                    ><ChevronLeft class="w-4 h-4 mr-1" />
                                    Prev</Button
                                >
                                <Button
                                    :disabled="
                                        categoryPagination.current_page ===
                                        categoryPagination.last_page
                                    "
                                    @click="
                                        fetchCategories(
                                            categoryPagination.current_page + 1,
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    class="h-8"
                                    >Next <ChevronRight class="w-4 h-4 ml-1"
                                /></Button>
                            </div>
                        </div>
                    </div>

                    <!-- GRID VIEW -->
                    <div v-else key="grid" class="animate-fade-in">
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                        >
                            <div
                                v-for="cat in categories"
                                :key="cat.id"
                                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 hover:border-[var(--interactive-primary)] hover:shadow-md transition-all group relative flex flex-col"
                            >
                                <!-- Checkbox -->
                                <div class="absolute top-4 left-4 z-10">
                                    <input
                                        type="checkbox"
                                        :checked="
                                            selectedCategories.has(cat.id)
                                        "
                                        @change="
                                            toggleSelection(
                                                cat.id,
                                                'categories',
                                            )
                                        "
                                        class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                    />
                                </div>

                                <!-- Actions -->
                                <div
                                    class="absolute top-3 right-3 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                    <button
                                        @click="openCategoryModal(cat)"
                                        class="p-1.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm rounded-md hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors"
                                    >
                                        <Edit2 class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        @click="deleteCategory(cat.id)"
                                        class="p-1.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm rounded-md hover:bg-red-50 hover:text-red-500 text-[var(--text-secondary)] transition-colors"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </div>

                                <!-- Header (Left Aligned) -->
                                <div
                                    class="flex items-center gap-3 mb-3 pl-7 pr-8"
                                >
                                    <div
                                        class="shrink-0 w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-900/10 flex items-center justify-center text-blue-600 dark:text-blue-400 border border-blue-100 dark:border-blue-900/20"
                                    >
                                        <Folder class="h-4 w-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3
                                            class="font-semibold text-[var(--text-primary)] text-sm leading-tight truncate"
                                            :title="cat.name"
                                        >
                                            {{ cat.name }}
                                        </h3>
                                        <p
                                            class="text-[10px] text-[var(--text-tertiary)] font-mono truncate mt-0.5"
                                        >
                                            {{ cat.slug }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Compact Stats Row -->
                                <div
                                    class="flex items-center justify-between gap-2 mb-3 py-2 px-3 bg-[var(--surface-secondary)]/30 rounded-lg border border-[var(--border-subtle)]"
                                >
                                    <div
                                        class="flex flex-col items-center flex-1 border-r border-[var(--border-subtle)] last:border-0"
                                    >
                                        <span
                                            class="text-xs font-semibold text-[var(--text-primary)]"
                                            >{{ cat.articles_count || 0 }}</span
                                        >
                                        <span
                                            class="text-[9px] text-[var(--text-tertiary)] uppercase tracking-wide"
                                            >Articles</span
                                        >
                                    </div>
                                    <div
                                        class="flex flex-col items-center flex-1 border-r border-[var(--border-subtle)] last:border-0"
                                    >
                                        <span
                                            class="text-xs font-semibold text-[var(--text-primary)]"
                                            >{{ cat.total_views || 0 }}</span
                                        >
                                        <span
                                            class="text-[9px] text-[var(--text-tertiary)] uppercase tracking-wide"
                                            >Views</span
                                        >
                                    </div>
                                    <div
                                        class="flex flex-col items-center flex-1"
                                    >
                                        <span
                                            class="text-xs font-semibold text-[var(--text-primary)]"
                                            >{{ cat.total_helpful || 0 }}</span
                                        >
                                        <span
                                            class="text-[9px] text-[var(--text-tertiary)] uppercase tracking-wide"
                                            >Votes</span
                                        >
                                    </div>
                                </div>

                                <!-- Footer -->
                                <div
                                    class="flex items-center justify-between text-[10px] mt-auto pt-2 border-t border-[var(--border-subtle)]"
                                >
                                    <span
                                        class="text-[var(--text-secondary)] font-medium"
                                        >Order: {{ cat.order }}</span
                                    >
                                    <span
                                        :class="
                                            cat.is_public
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-[var(--text-muted)]'
                                        "
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-current"
                                        ></span>
                                        {{
                                            cat.is_public ? "Public" : "Hidden"
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </template>

            <!-- ARTICLES TAB -->
            <template v-else>
                <Transition name="fade" mode="out-in">
                    <!-- LIST VIEW -->
                    <div
                        v-if="viewMode === 'list'"
                        key="list"
                        class="min-h-[calc(100vh-450px)] bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm overflow-hidden flex flex-col justify-between"
                    >
                        <div class="overflow-x-auto flex-1 h-full">
                            <table
                                class="w-full text-left text-sm relative border-collapse"
                            >
                                <thead
                                    class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] font-semibold text-xs uppercase tracking-wider sticky top-0 z-10 border-b border-[var(--border-default)]"
                                >
                                    <tr>
                                        <th class="w-12 px-6 py-4">
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    isAllSelected('articles')
                                                "
                                                @change="selectAll('articles')"
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                            />
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'title',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Title
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'title'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            v-if="showAuthorColumn"
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'author',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Author
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'author'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'category',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Category
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'category'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'views',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Engagement
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'views'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'helpful_count',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Helpful Score
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'helpful_count'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'is_published',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Status
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'is_published'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 cursor-pointer hover:text-[var(--text-primary)] transition-colors group select-none"
                                            @click="
                                                faqStore.setSort(
                                                    'articles',
                                                    'created_at',
                                                )
                                            "
                                        >
                                            <div
                                                class="flex items-center gap-1"
                                            >
                                                Created
                                                <span
                                                    v-if="
                                                        articleSort.field ===
                                                        'created_at'
                                                    "
                                                    class="text-[var(--interactive-primary)]"
                                                >
                                                    <component
                                                        :is="
                                                            articleSort.direction ===
                                                            'asc'
                                                                ? ArrowUp
                                                                : ArrowDown
                                                        "
                                                        class="w-3 h-3"
                                                    />
                                                </span>
                                                <ArrowUp
                                                    v-else
                                                    class="w-3 h-3 opacity-0 group-hover:opacity-30"
                                                />
                                            </div>
                                        </th>
                                        <th class="px-6 py-4 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[var(--border-strong)]"
                                >
                                    <tr v-if="articles.length === 0">
                                        <td
                                            :colspan="showAuthorColumn ? 9 : 8"
                                            class="px-6 h-96 text-center text-[var(--text-muted)] border-0"
                                        >
                                            <div
                                                class="flex flex-col items-center gap-3"
                                            >
                                                <div
                                                    class="p-4 rounded-full bg-[var(--surface-secondary)]"
                                                >
                                                    <FileText
                                                        class="w-8 h-8 opacity-40"
                                                    />
                                                </div>
                                                <span class="font-medium"
                                                    >No articles found.</span
                                                >
                                            </div>
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="article in articles"
                                        :key="article.id"
                                        class="group hover:bg-[var(--surface-secondary)] transition-colors"
                                    >
                                        <td class="px-6 py-4">
                                            <input
                                                type="checkbox"
                                                :checked="
                                                    selectedArticles.has(
                                                        article.id,
                                                    )
                                                "
                                                @change="
                                                    toggleSelection(
                                                        article.id,
                                                        'articles',
                                                    )
                                                "
                                                class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                            />
                                        </td>
                                        <td
                                            class="px-6 py-4 font-medium text-[var(--text-primary)]"
                                        >
                                            {{ article.title }}
                                        </td>
                                        <td
                                            v-if="showAuthorColumn"
                                            class="px-6 py-4"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <img
                                                    v-if="
                                                        article.author?.avatar
                                                    "
                                                    :src="article.author.avatar"
                                                    class="w-6 h-6 rounded-full object-cover"
                                                />
                                                <div
                                                    v-else
                                                    class="w-6 h-6 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-xs font-medium text-[var(--text-secondary)]"
                                                >
                                                    {{
                                                        article.author?.name?.charAt(
                                                            0,
                                                        ) || "?"
                                                    }}
                                                </div>
                                                <span
                                                    class="text-sm text-[var(--text-secondary)]"
                                                    >{{
                                                        article.author?.name ||
                                                        "Unknown"
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-subtle)]"
                                            >
                                                {{ article.category?.name }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-[var(--text-secondary)] text-xs"
                                        >
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <span
                                                    class="flex items-center gap-1.5"
                                                    title="Views"
                                                >
                                                    <Eye class="w-3.5 h-3.5" />
                                                    {{ article.views }}
                                                </span>
                                                <span
                                                    class="flex items-center gap-1.5 text-green-600"
                                                    title="Helpful"
                                                >
                                                    <ThumbsUp
                                                        class="w-3.5 h-3.5"
                                                    />
                                                    {{
                                                        article.helpful_count ||
                                                        0
                                                    }}
                                                </span>
                                                <span
                                                    class="flex items-center gap-1.5 text-red-600"
                                                    title="Not Helpful"
                                                >
                                                    <ThumbsDown
                                                        class="w-3.5 h-3.5"
                                                    />
                                                    {{
                                                        article.unhelpful_count ||
                                                        0
                                                    }}
                                                </span>
                                                <span
                                                    class="flex items-center gap-1.5 text-blue-600"
                                                    title="Comments"
                                                >
                                                    <MessageSquare
                                                        class="w-3.5 h-3.5"
                                                    />
                                                    {{
                                                        article.comments_count ||
                                                        0
                                                    }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <div
                                                    class="w-16 h-1.5 bg-[var(--surface-tertiary)] rounded-full overflow-hidden"
                                                >
                                                    <div
                                                        class="h-full bg-green-500 rounded-full"
                                                        :style="{
                                                            width:
                                                                calculateHelpfulPercentage(
                                                                    article,
                                                                ) + '%',
                                                        }"
                                                    ></div>
                                                </div>
                                                <span
                                                    class="text-xs font-mono text-[var(--text-secondary)]"
                                                    >{{
                                                        calculateHelpfulPercentage(
                                                            article,
                                                        )
                                                    }}%</span
                                                >
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span
                                                :class="
                                                    article.is_published
                                                        ? 'bg-green-500/10 text-green-700 dark:text-green-400 border-transparent'
                                                        : 'bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border-transparent'
                                                "
                                                class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full"
                                            >
                                                <span
                                                    class="w-1.5 h-1.5 rounded-full bg-current"
                                                ></span>
                                                {{
                                                    article.is_published
                                                        ? "Published"
                                                        : "Draft"
                                                }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-6 py-4 text-xs text-[var(--text-secondary)]"
                                        >
                                            {{
                                                article.created_at
                                                    ? format(
                                                          new Date(
                                                              article.created_at,
                                                          ),
                                                          "MMM d, yyyy",
                                                      )
                                                    : "-"
                                            }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="flex justify-end gap-1">
                                                <router-link
                                                    :to="{
                                                        name: 'public.faq.article',
                                                        params: {
                                                            slug: article.slug,
                                                        },
                                                    }"
                                                    target="_blank"
                                                    class="p-2 hover:bg-[var(--surface-tertiary)] rounded-full text-[var(--text-secondary)] transition-colors"
                                                    title="View Public"
                                                >
                                                    <Eye class="w-4 h-4" />
                                                </router-link>
                                                <button
                                                    @click="
                                                        openArticleEditor(
                                                            article,
                                                        )
                                                    "
                                                    class="p-2 hover:bg-[var(--surface-tertiary)] rounded-full text-[var(--text-secondary)] transition-colors"
                                                    title="Edit"
                                                >
                                                    <Edit2 class="w-4 h-4" />
                                                </button>
                                                <button
                                                    @click="
                                                        deleteArticle(
                                                            article.id,
                                                        )
                                                    "
                                                    class="p-2 hover:bg-red-50 hover:text-red-500 rounded-full text-[var(--text-secondary)] transition-colors"
                                                    title="Delete"
                                                >
                                                    <Trash2 class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Article Pagination -->
                        <div
                            v-if="articlePagination.total > 0"
                            class="flex items-center justify-between px-6 py-4 border-t border-[var(--border-default)] bg-[var(--surface-elevated)]"
                        >
                            <p class="text-xs text-[var(--text-secondary)]">
                                Showing
                                {{
                                    (articlePagination.current_page - 1) *
                                        perPage +
                                    1
                                }}
                                -
                                {{
                                    Math.min(
                                        articlePagination.current_page *
                                            perPage,
                                        articlePagination.total,
                                    )
                                }}
                                of {{ articlePagination.total }}
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    :disabled="
                                        articlePagination.current_page === 1
                                    "
                                    @click="
                                        fetchArticles(
                                            articlePagination.current_page - 1,
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    class="h-8"
                                    ><ChevronLeft class="w-4 h-4 mr-1" />
                                    Prev</Button
                                >
                                <Button
                                    :disabled="
                                        articlePagination.current_page ===
                                        articlePagination.last_page
                                    "
                                    @click="
                                        fetchArticles(
                                            articlePagination.current_page + 1,
                                        )
                                    "
                                    size="sm"
                                    variant="outline"
                                    class="h-8"
                                    >Next <ChevronRight class="w-4 h-4 ml-1"
                                /></Button>
                            </div>
                        </div>
                    </div>

                    <!-- GRID VIEW -->
                    <div v-else key="grid" class="animate-fade-in p-6">
                        <div
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                        >
                            <div
                                v-for="article in articles"
                                :key="article.id"
                                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 hover:border-[var(--interactive-primary)] hover:shadow-md transition-all group relative flex flex-col h-full"
                            >
                                <!-- Checkbox -->
                                <div class="absolute top-4 left-4 z-10">
                                    <input
                                        type="checkbox"
                                        :checked="
                                            selectedArticles.has(article.id)
                                        "
                                        @change="
                                            toggleSelection(
                                                article.id,
                                                'articles',
                                            )
                                        "
                                        class="rounded border-[var(--border-strong)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] bg-[var(--surface-elevated)]"
                                    />
                                </div>

                                <!-- Floating Actions -->
                                <div
                                    class="absolute top-3 right-3 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                    <button
                                        @click="openArticleEditor(article)"
                                        class="p-1.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm rounded-md hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] transition-colors"
                                        title="Edit"
                                    >
                                        <Edit2 class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        @click="deleteArticle(article.id)"
                                        class="p-1.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] shadow-sm rounded-md hover:bg-red-50 hover:text-red-500 text-[var(--text-secondary)] transition-colors"
                                        title="Delete"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </div>

                                <!-- Header -->
                                <div class="pl-7 pr-4 mb-3">
                                    <span
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-[var(--surface-secondary)] text-[var(--text-tertiary)] border border-[var(--border-subtle)] mb-2 max-w-full truncate"
                                    >
                                        {{
                                            article.category?.name ||
                                            "Uncategorized"
                                        }}
                                    </span>
                                    <h3
                                        class="font-bold text-[var(--text-primary)] text-base leading-snug line-clamp-2 mb-2"
                                        :title="article.title"
                                    >
                                        {{ article.title }}
                                    </h3>
                                    <p
                                        class="text-xs text-[var(--text-secondary)] line-clamp-2 h-8 leading-4"
                                    >
                                        {{
                                            stripHtml(
                                                article.content,
                                            ).substring(0, 120)
                                        }}
                                    </p>
                                </div>

                                <!-- Compact Stats Row -->
                                <div
                                    class="flex items-center justify-between gap-2 mb-3 py-2 px-3 bg-[var(--surface-secondary)]/30 rounded-lg border border-[var(--border-subtle)] mt-auto"
                                >
                                    <div
                                        class="flex flex-col items-center flex-1 border-r border-[var(--border-subtle)] last:border-0"
                                    >
                                        <div
                                            class="flex items-center gap-1 text-[var(--text-secondary)]"
                                        >
                                            <Eye class="w-3 h-3" />
                                            <span
                                                class="text-xs font-semibold"
                                                >{{ article.views || 0 }}</span
                                            >
                                        </div>
                                    </div>
                                    <div
                                        class="flex flex-col items-center flex-1 border-r border-[var(--border-subtle)] last:border-0"
                                    >
                                        <div class="flex items-center gap-1">
                                            <span
                                                class="flex items-center gap-0.5 text-green-600 dark:text-green-400"
                                            >
                                                <ThumbsUp class="w-3 h-3" />
                                                <span
                                                    class="text-xs font-semibold"
                                                    >{{
                                                        article.helpful_count ||
                                                        0
                                                    }}</span
                                                >
                                            </span>
                                        </div>
                                    </div>
                                    <div
                                        class="flex flex-col items-center flex-1"
                                    >
                                        <div
                                            class="flex items-center gap-1 text-blue-600 dark:text-blue-400"
                                        >
                                            <MessageSquare class="w-3 h-3" />
                                            <span
                                                class="text-xs font-semibold"
                                                >{{
                                                    article.comments_count || 0
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Footer -->
                                <div
                                    class="flex items-center justify-between text-[10px] pt-2 border-t border-[var(--border-subtle)]"
                                >
                                    <div
                                        class="flex items-center gap-1.5 text-[var(--text-secondary)]"
                                    >
                                        <User class="w-3 h-3" />
                                        <span>{{
                                            article.author?.name || "Unknown"
                                        }}</span>
                                    </div>
                                    <span
                                        :class="
                                            article.is_published
                                                ? 'text-green-600 dark:text-green-400'
                                                : 'text-amber-600 dark:text-amber-400'
                                        "
                                        class="flex items-center gap-1.5 font-medium"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-current"
                                        ></span>
                                        {{
                                            article.is_published
                                                ? "Published"
                                                : "Draft"
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </template>
        </div>

        <!-- Category Modal -->
        <div
            v-if="showCategoryModal"
            class="modal-overlay flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
            style="z-index: 9999"
        >
            <div
                class="modal-content w-full max-w-md animate-fade-in-up bg-[var(--surface-elevated)] rounded-xl shadow-2xl border border-[var(--border-subtle)]"
            >
                <div
                    class="px-6 py-4 border-b border-[var(--border-default)] flex items-center justify-between"
                >
                    <h3
                        class="text-lg font-bold text-[var(--text-primary)] tracking-tight"
                    >
                        {{ categoryForm.id ? "Edit" : "New" }} Category
                    </h3>
                    <button
                        @click="showCategoryModal = false"
                        class="text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    >
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label
                            class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)] mb-1.5"
                            >Name</label
                        >
                        <input
                            v-model="categoryForm.name"
                            type="text"
                            class="input w-full bg-[var(--surface-secondary)] border-[var(--border-default)] focus:bg-[var(--surface-elevated)]"
                            placeholder="e.g. Getting Started"
                        />
                        <p
                            v-if="categoryErrors.name"
                            class="text-xs text-red-500 mt-1"
                        >
                            {{ categoryErrors.name[0] }}
                        </p>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)] mb-1.5"
                            >Description</label
                        >
                        <textarea
                            v-model="categoryForm.description"
                            class="input w-full bg-[var(--surface-secondary)] border-[var(--border-default)] focus:bg-[var(--surface-elevated)] min-h-[100px]"
                            placeholder="Brief description of this category..."
                        ></textarea>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)] mb-1.5"
                            >Order</label
                        >
                        <input
                            v-model="categoryForm.order"
                            type="number"
                            class="input w-full bg-[var(--surface-secondary)] border-[var(--border-default)] focus:bg-[var(--surface-elevated)]"
                        />
                    </div>
                    <div
                        class="flex items-center gap-3 p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)] opacity-50 cursor-not-allowed"
                        title="Publication status is managed via bulk actions"
                    >
                        <input
                            type="checkbox"
                            checked
                            disabled
                            class="rounded border-[var(--border-strong)] text-[var(--text-muted)] w-5 h-5 bg-gray-100"
                        />
                        <div>
                            <label
                                class="text-sm font-medium block text-[var(--text-muted)]"
                                >Public Visibility</label
                            >
                            <p class="text-xs text-[var(--text-muted)]">
                                Default: Public. Use actions to unpublish.
                            </p>
                        </div>
                    </div>
                </div>
                <div
                    class="px-6 py-4 bg-[var(--surface-secondary)]/50 flex justify-end gap-3 rounded-b-xl border-t border-[var(--border-default)]"
                >
                    <Button variant="ghost" @click="showCategoryModal = false"
                        >Cancel</Button
                    >
                    <Button @click="saveCategory">Save Category</Button>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <Modal
            :open="showConfirmModal"
            @close="showConfirmModal = false"
            size="sm"
            :title="`${confirmAction?.type === 'delete' ? 'Delete' : confirmAction?.type === 'publish' ? 'Publish' : 'Unpublish'} ${confirmAction?.target === 'categories' ? 'Categories' : 'Articles'}`"
            :description="`Confirm action for ${confirmAction?.ids?.length || 0} items`"
            :show-close="true"
        >
            <div class="text-center space-y-4">
                <!-- Icon -->
                <div class="flex justify-center">
                    <div
                        :class="{
                            'bg-gradient-to-br from-red-500/20 to-red-600/10 ring-1 ring-red-500/30':
                                confirmAction?.type === 'delete',
                            'bg-gradient-to-br from-amber-500/20 to-amber-600/10 ring-1 ring-amber-500/30':
                                confirmAction?.type === 'unpublish',
                            'bg-gradient-to-br from-emerald-500/20 to-emerald-600/10 ring-1 ring-emerald-500/30':
                                confirmAction?.type === 'publish',
                        }"
                        class="w-14 h-14 rounded-2xl flex items-center justify-center"
                    >
                        <Trash2
                            v-if="confirmAction?.type === 'delete'"
                            class="w-7 h-7 text-red-500"
                        />
                        <EyeOff
                            v-else-if="confirmAction?.type === 'unpublish'"
                            class="w-7 h-7 text-amber-500"
                        />
                        <Eye v-else class="w-7 h-7 text-emerald-500" />
                    </div>
                </div>

                <!-- Title & Description -->
                <div>
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)]"
                    >
                        {{
                            confirmAction?.type === "delete"
                                ? "Delete"
                                : confirmAction?.type === "publish"
                                  ? "Publish"
                                  : "Unpublish"
                        }}
                        {{ confirmAction?.ids?.length || 0 }}
                        {{
                            confirmAction?.target === "categories"
                                ? "Category"
                                : "Article"
                        }}{{
                            (confirmAction?.ids?.length || 0) > 1 ? "s" : ""
                        }}?
                    </h3>
                    <p class="text-sm text-[var(--text-secondary)] mt-1">
                        <template
                            v-if="
                                confirmAction?.type === 'delete' &&
                                confirmAction?.target === 'categories'
                            "
                        >
                            This will permanently delete all associated
                            articles. This action cannot be undone.
                        </template>
                        <template v-else-if="confirmAction?.type === 'delete'">
                            This action cannot be undone.
                        </template>
                        <template
                            v-else-if="confirmAction?.type === 'unpublish'"
                        >
                            Articles will be hidden from public view.
                        </template>
                        <template v-else>
                            Articles will become visible to all users.
                        </template>
                    </p>
                </div>

                <!-- Reason field (for delete and unpublish only) -->
                <div
                    v-if="
                        confirmAction?.type === 'delete' ||
                        confirmAction?.type === 'unpublish'
                    "
                    class="text-left"
                >
                    <label
                        class="block text-sm font-medium text-[var(--text-secondary)] mb-1.5"
                    >
                        Reason <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        v-model="confirmReason"
                        rows="2"
                        class="w-full px-3 py-2.5 rounded-xl border bg-[var(--surface-secondary)] text-[var(--text-primary)] border-[var(--border-default)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-transparent resize-none text-sm placeholder:text-[var(--text-muted)]"
                        :placeholder="
                            confirmAction?.type === 'delete'
                                ? 'Why are you deleting this?'
                                : 'Why are you unpublishing this?'
                        "
                    ></textarea>
                </div>

                <!-- Error message -->
                <div
                    v-if="confirmError"
                    class="p-3 rounded-xl bg-red-500/10 border border-red-500/20"
                >
                    <p class="text-sm text-red-400">{{ confirmError }}</p>
                </div>
            </div>

            <template #footer>
                <Button
                    variant="ghost"
                    @click="showConfirmModal = false"
                    :disabled="confirmLoading"
                >
                    Cancel
                </Button>
                <Button
                    :variant="
                        confirmAction?.type === 'delete'
                            ? 'danger'
                            : confirmAction?.type === 'publish'
                              ? 'primary'
                              : 'secondary'
                    "
                    @click="executeConfirmedAction"
                    :loading="confirmLoading"
                >
                    {{
                        confirmAction?.type === "delete"
                            ? "Continue"
                            : confirmAction?.type === "publish"
                              ? "Publish"
                              : "Continue"
                    }}
                </Button>
            </template>
        </Modal>

        <!-- Password Confirmation Modal -->
        <ConfirmPasswordModal
            :open="showPasswordModal"
            @update:open="
                showPasswordModal = $event;
                passwordError = '';
            "
            :title="
                confirmAction?.type === 'delete'
                    ? 'Confirm Deletion'
                    : 'Confirm Unpublish'
            "
            :description="`Enter your password to ${confirmAction?.type} ${confirmAction?.ids?.length || 0} ${confirmAction?.target === 'categories' ? 'category' : 'article'}${(confirmAction?.ids?.length || 0) > 1 ? 's' : ''}.`"
            :loading="confirmLoading"
            :submit-text="
                confirmAction?.type === 'delete' ? 'Delete' : 'Unpublish'
            "
            :submit-variant="
                confirmAction?.type === 'delete' ? 'danger' : 'secondary'
            "
            :external-error="passwordError"
            :show-reason="confirmAction?.type === 'unpublish' || confirmAction?.type === 'delete'"
            @confirm="handlePasswordConfirm"
            @cancel="
                showPasswordModal = false;
                passwordError = '';
            "
        >
        </ConfirmPasswordModal>
    </div>
</template>

<style scoped>
.list-enter-active,
.list-leave-active {
    transition: all 0.3s ease;
}
.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateY(20px);
}
</style>
