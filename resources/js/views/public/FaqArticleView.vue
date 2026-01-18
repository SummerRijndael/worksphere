<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import PublicLayout from "@/layouts/PublicLayout.vue";
import CommentItem from "@/components/public/CommentItem.vue";
import { PageLoader, Button } from "@/components/ui";
import {
    ChevronLeft,
    Calendar,
    User,
    ThumbsUp,
    ThumbsDown,
    CheckCircle,
    MessageSquare,
    Send,
    Download,
    FileText,
    File as FileIcon,
} from "lucide-vue-next";
import api from "@/lib/api";
import { format } from "date-fns";
import useRecaptcha from "@/composables/useRecaptcha";
import { useFingerprint } from "@/composables/useFingerprint";
import RecaptchaChallengeModal from "@/components/common/RecaptchaChallengeModal.vue";
import { useAuthStore } from "@/stores/auth";
import DOMPurify from "dompurify";

const MAX_COMMENT_LENGTH = 5000;

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const isLoading = ref(true);
const article = ref<any>(null);
const hasVoted = ref(false);

// Comment State
const isSubmittingComment = ref(false);
const showRecaptchaModal = ref(false);
const commentForm = ref({
    name: "",
    content: "",
});
const commentErrors = ref<any>({});
const isContentExpanded = ref(false);

// Pagination State
const currentPage = ref(1);
const isLoadingMoreComments = ref(false);
const hasMoreComments = computed(() => {
    if (!article.value) return false;
    // article.comments is the currently loaded array
    // article.comments_count is the total from backend
    return article.value.comments.length < article.value.comments_count;
});

const { executeRecaptcha } = useRecaptcha();
const { getFingerprint } = useFingerprint();

const commentCharCount = computed(() => commentForm.value.content.length);
const isOverLimit = computed(() => commentCharCount.value > MAX_COMMENT_LENGTH);

const sanitizedContent = computed(() => {
    if (!article.value?.content) return "";
    return DOMPurify.sanitize(article.value.content, {
        ADD_TAGS: ["iframe"],
        ADD_ATTR: [
            "allow",
            "allowfullscreen",
            "frameborder",
            "scrolling",
            "target",
            "style",
            "class",
        ],
    });
});

const formatSize = (bytes: number) => {
    if (bytes === 0 || !bytes) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
};

const getFileIcon = (mimeType: string) => {
    if (mimeType === "application/pdf") return FileText;
    if (mimeType?.includes("sheet") || mimeType?.includes("excel"))
        return FileText; // Could import specific xls icon
    return FileIcon;
};

const fetchArticle = async () => {
    try {
        const response = await api.get(`/api/public/faq/${route.params.slug}`);
        article.value = response.data.data;
        checkVoteStatus();
    } catch (error: any) {
        console.error("Failed to fetch article", error);
        if (error.response?.status === 404) {
            router.push({ name: 'not-found' });
        } else {
            router.push({ name: 'public.faq' });
        }
    } finally {
        isLoading.value = false;
    }
};

const checkVoteStatus = () => {
    if (article.value) {
        const voteKey = `faq_vote_${article.value.id}`;
        hasVoted.value = !!localStorage.getItem(voteKey);
    }
};

const vote = async (isHelpful: boolean) => {
    if (!article.value || hasVoted.value) return;

    try {
        // Get fingerprint if available
        const fingerprint = await getFingerprint();

        await api.post(`/api/public/faq/${article.value.id}/vote`, {
            is_helpful: isHelpful,
            fingerprint: fingerprint,
        });

        hasVoted.value = true;
        localStorage.setItem(
            `faq_vote_${article.value.id}`,
            isHelpful ? "up" : "down",
        );
    } catch (error: any) {
        // Handle already voted response
        if (error.response?.data?.already_voted) {
            hasVoted.value = true;
            localStorage.setItem(`faq_vote_${article.value.id}`, "voted");
        }
        console.error("Failed to submit vote", error);
    }
};

const submitComment = async (v2Token: string | null = null) => {
    if (!article.value) return;
    isSubmittingComment.value = true;
    commentErrors.value = {};

    try {
        // Sanitize content
        const sanitizedContent = DOMPurify.sanitize(commentForm.value.content, {
            ALLOWED_TAGS: [],
        });
        const sanitizedName = commentForm.value.name
            ? DOMPurify.sanitize(commentForm.value.name, { ALLOWED_TAGS: [] })
            : "";

        // Prepare payload
        const payload: any = {
            content: sanitizedContent,
        };

        if (!authStore.user) {
            payload.name = sanitizedName;
        }

        if (v2Token) {
            payload.recaptcha_v2_token = v2Token;
        } else {
            // Get V3 token
            const token = await executeRecaptcha("faq_comment");
            if (token) {
                payload.recaptcha_token = token;
            } else {
                console.warn(
                    "Recaptcha V3 token not available, might be disabled.",
                );
                // Still submit? Backend validation will fail if required.
                payload.recaptcha_token = "dummy"; // This will cause configured fail or explicit validation error
            }
        }

        const response = await api.post(
            `/api/public/faq/${article.value.id}/comment`,
            payload,
        );

        // Success
        commentForm.value.content = "";
        commentForm.value.name = "";

        // Add new comment to list
        if (response.data.comment) {
            article.value.comments.unshift({
                ...response.data.comment,
                created_at: new Date().toISOString(),
                user_avatar: authStore.avatarUrl,
                name: authStore.user?.name || response.data.comment.name,
            });
        }
    } catch (error: any) {
        if (error.response?.status === 422) {
            const data = error.response.data;
            if (data.require_v2) {
                // Trigger V2 Modal
                showRecaptchaModal.value = true;
            } else if (data.errors) {
                commentErrors.value = data.errors;
            } else {
                // Generic error
                console.error(data.message);
            }
        } else if (error.response?.status === 429) {
            alert("You are commenting too fast. Please wait a moment.");
        } else {
            console.error("Comment submission failed", error);
        }
    } finally {
        isSubmittingComment.value = false;
    }
};

const handleChallengeSuccess = (token: string) => {
    showRecaptchaModal.value = false;
    submitComment(token);
};

const loadMoreComments = async () => {
    if (!article.value || isLoadingMoreComments.value) return;

    isLoadingMoreComments.value = true;
    try {
        const nextPage = currentPage.value + 1;
        const response = await api.get(
            `/api/public/faq/${article.value.id}/comments?page=${nextPage}`
        );

        // Append new comments
        const newComments = response.data.data;
        if (newComments.length > 0) {
            article.value.comments.push(...newComments);
            currentPage.value = nextPage;
        }
    } catch (error) {
        console.error("Failed to load more comments", error);
    } finally {
        isLoadingMoreComments.value = false;
    }
};

onMounted(() => {
    fetchArticle();
});
</script>

<template>
    <PublicLayout>
        <PageLoader :show="isLoading" />

        <div v-if="article" class="max-w-7xl mx-auto px-4 sm:px-6 py-12">
            <!-- Breadcrumb / Back -->
            <div class="mb-8">
                <RouterLink
                    to="/public/faq"
                    class="inline-flex items-center text-sm text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] transition-colors"
                >
                    <ChevronLeft class="h-4 w-4 mr-1" />
                    Back to Knowledge Base
                </RouterLink>
            </div>

            <!-- Article Header -->
            <div class="mb-5 border-b border-[var(--border-default)] pb-1.5">
                <div class="flex items-center gap-2 mb-4">
                    <span
                        class="px-3 py-1 rounded-full text-xs font-medium bg-[var(--color-primary-100)] text-[var(--color-primary-700)] dark:bg-[var(--color-primary-900)] dark:text-[var(--color-primary-300)]"
                    >
                        {{ article.category?.name }}
                    </span>
                </div>
                <h1
                    class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-6"
                >
                    {{ article.title }}
                </h1>

                <div
                    v-if="article.tags && article.tags.length > 0"
                    class="flex flex-wrap gap-2 mb-6"
                >
                    <span
                        v-for="tag in article.tags"
                        :key="tag"
                        class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-default)]"
                    >
                        #{{ tag }}
                    </span>
                </div>

                <div
                    class="mt-3 flex flex-wrap items-center gap-6 text-sm text-[var(--text-muted)]"
                >
                    <div class="flex items-center gap-2">
                        <User class="h-4 w-4" />
                        <span>{{
                            article.author?.name || "CoreSync Team"
                        }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Calendar class="h-4 w-4" />
                        <span
                            >Updated
                            {{
                                article.updated_at
                                    ? format(
                                          new Date(article.updated_at),
                                          "MMM d, yyyy",
                                      )
                                    : "Recently"
                            }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="prose prose-lg dark:prose-invert max-w-none">
                <div v-html="sanitizedContent"></div>
            </div>

            <!-- Downloads & Resources -->
            <div
                v-if="article.attachments && article.attachments.length > 0"
                class="mt-12 mb-8"
            >
                <h3
                    class="text-lg font-bold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                >
                    <Download class="w-5 h-5" />
                    Downloads & Resources
                </h3>
                <div
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
                >
                    <a
                        v-for="file in article.attachments"
                        :key="file.id"
                        :href="file.download_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex items-center gap-3 p-4 rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)] hover:border-[var(--interactive-primary)] hover:shadow-md transition-all group text-left"
                    >
                        <div
                            class="h-10 w-10 rounded-lg bg-[var(--surface-tertiary)] flex items-center justify-center shrink-0 group-hover:bg-[var(--interactive-primary)] group-hover:text-white transition-colors"
                        >
                            <component
                                :is="getFileIcon(file.mime_type)"
                                class="w-5 h-5"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p
                                class="font-medium text-[var(--text-primary)] truncate"
                                :title="file.name"
                            >
                                {{ file.name }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">
                                {{ formatSize(file.size) }}
                            </p>
                        </div>
                        <Download
                            class="w-4 h-4 ml-auto text-[var(--text-muted)] opacity-0 group-hover:opacity-100 transition-opacity -translate-x-2 group-hover:translate-x-0"
                        />
                    </a>
                </div>
            </div>

            <!-- Helpful? Section -->
            <div class="mt-12 pt-8 border-t border-[var(--border-default)]">
                <div
                    class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-[var(--surface-secondary)] p-6 rounded-xl border border-[var(--border-default)] shadow-sm"
                >
                    <div v-if="!hasVoted">
                        <p
                            class="font-medium text-[var(--text-primary)] text-lg mb-1"
                        >
                            Was this article helpful?
                        </p>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Your feedback helps us improve our documentation.
                        </p>
                    </div>
                    <div
                        v-else
                        class="flex items-center gap-3 text-green-600 animate-fade-in"
                    >
                        <CheckCircle class="w-6 h-6" />
                        <div>
                            <p class="font-medium">
                                Thank you for your feedback!
                            </p>
                            <p class="text-sm opacity-80">
                                We appreciate your input.
                            </p>
                        </div>
                    </div>

                    <div v-if="!hasVoted" class="flex gap-3">
                        <button
                            @click="vote(true)"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:bg-green-50 dark:hover:bg-green-900/20 hover:border-green-200 hover:text-green-600 transition-all active:scale-95 shadow-sm"
                        >
                            <ThumbsUp class="w-4 h-4" />
                            <span>Yes, thanks!</span>
                        </button>
                        <button
                            @click="vote(false)"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:bg-red-50 dark:hover:bg-red-900/20 hover:border-red-200 hover:text-red-600 transition-all active:scale-95 shadow-sm"
                        >
                            <ThumbsDown class="w-4 h-4" />
                            <span>Not really</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Comments Section -->
            <div class="mt-12">
                <h3
                    class="text-2xl font-bold text-[var(--text-primary)] mb-6 flex items-center gap-2"
                >
                    <MessageSquare class="w-6 h-6" />
                    Comments ({{ article.comments_count || 0 }})
                </h3>

                <!-- Comment Form -->
                <div
                    class="mb-10 mt-3 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl p-6 shadow-sm"
                >
                    <h4 class="text-lg font-medium mb-4">Leave a comment</h4>
                    <div class="space-y-4">
                        <div v-if="!authStore.user">
                            <label class="block text-sm font-medium mb-1"
                                >Name
                                <span class="text-xs text-[var(--text-muted)]"
                                    >(Optional)</span
                                ></label
                            >
                            <input
                                v-model="commentForm.name"
                                type="text"
                                class="input"
                                placeholder="Your name"
                            />
                            <p
                                v-if="commentErrors.name"
                                class="text-xs text-red-500 mt-1"
                            >
                                {{ commentErrors.name[0] }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"
                                >Comment</label
                            >
                            <textarea
                                v-model="commentForm.content"
                                class="input min-h-[100px]"
                                placeholder="Ask a question or share your thoughts..."
                                :maxlength="MAX_COMMENT_LENGTH"
                            ></textarea>
                            <div class="flex items-center justify-between mt-1">
                                <p
                                    v-if="commentErrors.content"
                                    class="text-xs text-red-500"
                                >
                                    {{ commentErrors.content[0] }}
                                </p>
                                <span v-else></span>
                                <span
                                    :class="[
                                        'text-xs',
                                        isOverLimit
                                            ? 'text-red-500 font-medium'
                                            : 'text-[var(--text-muted)]',
                                    ]"
                                >
                                    {{ commentCharCount.toLocaleString() }} /
                                    {{ MAX_COMMENT_LENGTH.toLocaleString() }}
                                </span>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <Button
                                @click="submitComment()"
                                :disabled="
                                    isSubmittingComment ||
                                    !commentForm.content.trim() ||
                                    isOverLimit
                                "
                            >
                                <Send class="w-4 h-4 mr-2" />
                                {{
                                    isSubmittingComment
                                        ? "Posting..."
                                        : "Post Comment"
                                }}
                            </Button>
                        </div>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mt-2">
                        This site is protected by reCAPTCHA and the Google
                        <a
                            href="https://policies.google.com/privacy"
                            class="underline"
                            >Privacy Policy</a
                        >
                        and
                        <a
                            href="https://policies.google.com/terms"
                            class="underline"
                            >Terms of Service</a
                        >
                        apply.
                    </p>
                </div>

                <!-- Comments List -->
                    <div class="space-y-6">
                         <!-- Comments List -->
                         <div v-if="article.comments && article.comments.length > 0">
                            <CommentItem
                                v-for="comment in article.comments"
                                :key="comment.id"
                                :comment="comment"
                                class="mb-6 last:mb-0"
                            />
                         </div>
                         <div
                            v-else
                            class="text-center py-8 text-[var(--text-secondary)]"
                         >
                            No comments yet. Be the first to start the discussion!
                         </div>

                         <!-- Load More Button -->
                         <div
                            v-if="hasMoreComments"
                            class="flex justify-center pt-4"
                         >
                            <Button
                                variant="secondary"
                                :disabled="isLoadingMoreComments"
                                @click="loadMoreComments"
                            >
                                <template v-if="isLoadingMoreComments">
                                    <span class="animate-spin mr-2">⟳</span>
                                    Loading...
                                </template>
                                <template v-else>
                                    Load older comments
                                </template>
                            </Button>
                         </div>
                    </div>
            </div>

            <!-- Privacy Notice -->
            <div class="mt-8 pt-6 border-t border-[var(--border-default)]">
                <p class="text-xs text-[var(--text-tertiary)] text-center">
                    We use cookies and browser fingerprinting to prevent abuse
                    and ensure fair voting. By using these features, you agree
                    to our
                    <router-link
                        to="/privacy"
                        class="text-[var(--interactive-primary)] hover:underline"
                        >Privacy Policy</router-link
                    >.
                </p>
            </div>
        </div>

        <RecaptchaChallengeModal
            :show="showRecaptchaModal"
            @close="showRecaptchaModal = false"
            @success="handleChallengeSuccess"
        />
    </PublicLayout>
</template>

<style scoped>
/* Float Clearing for Article Content */
.prose {
    display: flow-root; /* Creates a new BFC to contain floats */
}

/* Clear floats before block elements */
.prose :deep(h1),
.prose :deep(h2),
.prose :deep(h3),
.prose :deep(h4),
.prose :deep(h5),
.prose :deep(h6),
.prose :deep(ul),
.prose :deep(ol),
.prose :deep(blockquote),
.prose :deep(pre),
.prose :deep(hr) {
    clear: both;
}

/* Table Styling */
.prose :deep(table) {
    border-collapse: collapse;
    margin: 1rem 0;
    width: 100%;
    table-layout: auto;
}

.prose :deep(th),
.prose :deep(td) {
    border: 1px solid var(--border-default);
    padding: 0.5rem 0.75rem;
    min-width: 50px;
}

.prose :deep(th) {
    background-color: var(--surface-secondary);
    font-weight: 600;
    text-align: left;
}

.prose :deep(td) {
    background-color: var(--surface-elevated);
}
</style>
