<script setup lang="ts">
import { ref, onMounted } from "vue";
import { RouterLink } from "vue-router";
import { Button, Card, PageLoader } from "@/components/ui";
import PublicLayout from "@/layouts/PublicLayout.vue";
import { animate, stagger } from "animejs";
import {
    LayoutGrid,
    Users,
    BarChart3,
    ShieldCheck,
    Repeat,
    ArrowRight,
    Play,
    CheckCircle2,
    Globe,
    Zap,
    Star,
} from "lucide-vue-next";
import { appConfig } from "@/config/app";

const isLoading = ref(true);

// Animation refs
const heroRef = ref<HTMLElement | null>(null);
const logoRef = ref<HTMLElement | null>(null);
const solutionsRef = ref<HTMLElement | null>(null);
const pricingRef = ref<HTMLElement | null>(null);
const reviewsRef = ref<HTMLElement | null>(null);
const scaleRef = ref<HTMLElement | null>(null);
const ctaRef = ref<HTMLElement | null>(null);

const features = [
    {
        title: "Unified Workspace",
        description:
            "Bring all your tools into one place. No more switching apps.",
        icon: LayoutGrid,
    },
    {
        title: "Real-time Collaboration",
        description: "Work together with your team in real-time, anywhere.",
        icon: Users,
    },
    {
        title: "Advanced Analytics",
        description:
            "Get deep insights into your team's performance with generated reports.",
        icon: BarChart3,
    },
    {
        title: "Enterprise Security",
        description:
            "Bank-grade security standards to keep your data safe and compliant.",
        icon: ShieldCheck,
    },
];

const plans = [
    {
        name: "Starter",
        price: "$0",
        description: "Perfect for small teams getting started.",
        features: [
            "Up to 5 users",
            "Basic Analytics",
            "Unlimited Projects",
            "Community Support",
        ],
        highlight: false,
    },
    {
        name: "Pro",
        price: "$29",
        description: "For growing teams that need more power.",
        features: [
            "Up to 20 users",
            "Advanced Analytics",
            "Priority Support",
            "Custom Workflows",
            "API Access",
        ],
        highlight: true,
    },
    {
        name: "Enterprise",
        price: "Custom",
        description: "Scalable solutions for large organizations.",
        features: [
            "Unlimited users",
            "Dudicated Success Manager",
            "SAML SSO",
            "Audit Logs",
            "SLA Guarantee",
        ],
        highlight: false,
    },
];

const reviews = [
    {
        content:
            "WorkSphere has completely transformed how we manage our projects. The real-time collaboration features are a game changer.",
        author: "Sarah J.",
        role: "Product Manager at TechFlow",
        avatar: "SJ",
    },
    {
        content:
            "The best project management tool we've used. Simple, intuitive, and powerful. Highly recommended for any team.",
        author: "Michael C.",
        role: "CTO at StartupInc",
        avatar: "MC",
    },
    {
        content:
            "We moved from Jira and haven't looked back. The interface is beautiful and the performance is incredible.",
        author: "Emily R.",
        role: "Director of Ops at GlobalCorp",
        avatar: "ER",
    },
];

// Re-using scroll animation logic from original file
function createScrollAnimation(
    elements:
        | HTMLElement
        | NodeListOf<Element>
        | HTMLCollection
        | Element[]
        | null,
    options: Record<string, unknown>,
    threshold = 0.2,
) {
    if (!elements) return;

    let targets: Element[] = [];
    if (elements instanceof HTMLElement) {
        targets = [elements];
    } else {
        targets = Array.from(elements as any);
    }

    if (targets.length === 0) return;

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animate(entry.target, options);
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold },
    );

    targets.forEach((el) => observer.observe(el));
}

onMounted(() => {
    setTimeout(() => {
        isLoading.value = false;

        // Hero Animation (Immediate)
        if (heroRef.value) {
            const elements = heroRef.value.querySelectorAll(".hero-animate");
            animate(elements, {
                opacity: [0, 1],
                translateY: [20, 0],
                delay: stagger(100),
                duration: 800,
                easing: "easeOutExpo",
            });
        }

        // Logo Scroll Animation
        if (logoRef.value) {
            animate(logoRef.value.children, {
                // Animate logos
                opacity: [0, 1],
                translateY: [20, 0],
                delay: stagger(100),
                duration: 1000,
                easing: "easeOutExpo",
            });
        }

        // Solutions/Features Scroll Animation
        if (solutionsRef.value) {
            const cards = solutionsRef.value.querySelectorAll(".feature-card");
            const sectionTitle =
                solutionsRef.value.querySelector(".section-title");

            createScrollAnimation(sectionTitle as HTMLElement, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
            });

            // Stagger animation needs special handling in scroll observer usually,
            // but we can just use the createScrollAnimation independently or batch them.
            // Simplified: Animate each card as it comes into view or batch them.
            // Let's batch them for the stagger effect if the container is visible.
            const observer = new IntersectionObserver(
                (entries) => {
                    if (entries[0].isIntersecting) {
                        animate(cards, {
                            opacity: [0, 1],
                            translateY: [50, 0],
                            delay: stagger(100),
                            duration: 800,
                            easing: "easeOutExpo",
                        });
                        observer.disconnect();
                    }
                },
                { threshold: 0.2 },
            );
            observer.observe(solutionsRef.value);
        }

        // Pricing Animation
        if (pricingRef.value) {
            const title = pricingRef.value.querySelector(".pricing-title");
            const cards = pricingRef.value.querySelectorAll(".pricing-card");

            createScrollAnimation(title as HTMLElement, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
            });

            createScrollAnimation(cards, {
                opacity: [0, 1],
                translateY: [50, 0],
                delay: stagger(100),
                duration: 800,
                easing: "easeOutExpo",
            });
        }

        // Reviews Animation
        if (reviewsRef.value) {
            const title = reviewsRef.value.querySelector(".reviews-title");
            const cards = reviewsRef.value.querySelectorAll(".review-card");

            createScrollAnimation(title as HTMLElement, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
            });

            createScrollAnimation(cards, {
                opacity: [0, 1],
                translateY: [50, 0],
                delay: stagger(100),
                duration: 800,
                easing: "easeOutExpo",
            });
        }

        // Global Scale Animation
        if (scaleRef.value) {
            const content = scaleRef.value.querySelector(".scale-content");
            const image = scaleRef.value.querySelector(".scale-image");

            createScrollAnimation(content as HTMLElement, {
                opacity: [0, 1],
                translateX: [-50, 0],
                duration: 1000,
                easing: "easeOutExpo",
            });
            createScrollAnimation(image as HTMLElement, {
                opacity: [0, 1],
                translateX: [50, 0],
                duration: 1000,
                delay: 200,
                easing: "easeOutExpo",
            });
        }

        // CTA Animation
        if (ctaRef.value) {
            createScrollAnimation(ctaRef.value.children as any, {
                opacity: [0, 1],
                translateY: [30, 0],
                delay: stagger(100),
                duration: 800,
                easing: "easeOutExpo",
            });
        }
    }, 800);
});
</script>

<template>
    <PageLoader :show="isLoading" />

    <PublicLayout>
        <!-- Hero Section -->
        <section
            ref="heroRef"
            class="relative pt-24 pb-32 overflow-hidden bg-[var(--color-landing-surface)] dark:bg-[var(--surface-primary)]"
        >
            <!-- Background Elements -->
            <div class="absolute inset-0 pointer-events-none">
                <div
                    class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full max-w-7xl"
                >
                    <div
                        class="absolute top-20 right-0 w-[600px] h-[600px] bg-[var(--color-landing-secondary)] rounded-full blur-[120px] opacity-20 dark:opacity-10 animate-pulse-slow"
                    ></div>
                    <div
                        class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-[var(--color-landing-primary)] rounded-full blur-[100px] opacity-10 dark:opacity-5"
                    ></div>
                </div>
            </div>

            <div
                class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center z-10"
            >
                <!-- Trust Badge -->
                <div class="flex justify-center">
                    <div
                        class="hero-animate opacity-0 inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/60 dark:bg-white/5 border border-[var(--color-neutral-200)] dark:border-white/10 backdrop-blur-sm mb-8 shadow-sm"
                    >
                        <span class="flex h-2 w-2 relative">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[var(--color-landing-cta)] opacity-75"
                            ></span>
                            <span
                                class="relative inline-flex rounded-full h-2 w-2 bg-[var(--color-landing-cta)]"
                            ></span>
                        </span>
                        <span
                            class="text-sm font-medium text-[var(--text-secondary)] dark:text-[var(--text-secondary)]"
                        >
                            Trusted by 500+ Enterprise Teams
                        </span>
                    </div>
                </div>

                <!-- Headline -->
                <h1
                    class="hero-animate opacity-0 text-5xl md:text-7xl font-bold tracking-tight text-[var(--text-primary)] dark:text-white mb-8 font-landing leading-[1.1]"
                >
                    The Operating System for <br class="hidden md:block" />
                    <span
                        class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--color-landing-primary)] to-[var(--color-landing-cta)]"
                    >
                        Modern Work
                    </span>
                </h1>

                <!-- Subheadline -->
                <p
                    class="hero-animate opacity-0 text-xl text-[var(--text-secondary)] dark:text-[var(--text-muted)] max-w-2xl mx-auto mb-10 leading-relaxed font-landing"
                >
                    {{ appConfig.name }} unifies project management, team
                    collaboration, and analytics into one intelligent platform.
                    Stop juggling tools and start delivering.
                </p>

                <!-- CTAs -->
                <div
                    class="hero-animate opacity-0 flex flex-col sm:flex-row items-center justify-center gap-4 mb-20"
                >
                    <RouterLink to="/auth/login">
                        <button
                            class="btn btn-primary bg-[var(--color-landing-cta)] hover:bg-orange-600 text-white px-8 py-4 text-lg rounded-xl shadow-lg shadow-orange-500/20 hover:shadow-orange-500/30 transition-all hover:-translate-y-1 cursor-pointer"
                        >
                            Start Free Trial
                            <ArrowRight class="w-5 h-5 ml-2" />
                        </button>
                    </RouterLink>

                    <button
                        class="btn btn-secondary bg-white dark:bg-white/10 text-[var(--text-primary)] dark:text-white border border-[var(--color-neutral-200)] dark:border-white/10 px-8 py-4 text-lg rounded-xl hover:bg-[var(--color-neutral-50)] dark:hover:bg-white/20 transition-all cursor-pointer"
                    >
                        <Play class="w-5 h-5 mr-2 fill-current" />
                        Watch Demo
                    </button>
                </div>

                <!-- Hero Image/Dashboard Preview -->
                <div class="hero-animate opacity-0 relative mx-auto max-w-5xl">
                    <div
                        class="relative rounded-2xl border border-[var(--color-neutral-200)] dark:border-white/10 bg-white/50 dark:bg-black/40 backdrop-blur-xl shadow-2xl overflow-hidden aspect-[16/9] group"
                    >
                        <div
                            class="absolute inset-0 bg-gradient-to-t from-white/20 to-transparent dark:from-black/40 z-10 pointer-events-none"
                        ></div>
                        <!-- Placeholder for Dashboard Image -->
                        <div
                            class="w-full h-full bg-[var(--color-neutral-100)] dark:bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--text-muted)]"
                        >
                            <img
                                src="/doc/screenshots/dashboard.png"
                                alt="Dashboard Preview"
                                class="w-full h-full object-cover object-top hover:scale-[1.01] transition-transform duration-700"
                            />
                        </div>
                    </div>

                    <!-- Floating Cards Decorations -->
                    <div
                        class="absolute -right-12 top-20 p-4 bg-white dark:bg-[var(--surface-elevated)] rounded-xl shadow-xl border border-[var(--color-neutral-100)] dark:border-white/10 animate-bounce-slow hidden lg:block"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400"
                            >
                                <CheckCircle2 class="w-6 h-6" />
                            </div>
                            <div>
                                <div
                                    class="text-sm font-bold text-[var(--text-primary)] dark:text-white"
                                >
                                    Project Complete
                                </div>
                                <div class="text-xs text-[var(--text-muted)]">
                                    Just now
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Social Proof / Logos -->
        <section
            class="py-12 border-y border-[var(--color-neutral-100)] dark:border-white/5 bg-white/50 dark:bg-[var(--surface-secondary)]"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" ref="logoRef">
                <p
                    class="text-center text-sm font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-8"
                >
                    Powering next-gen companies
                </p>
                <div
                    class="flex flex-wrap justify-center items-center gap-12 opacity-0"
                >
                    <!-- Simple Text Placeholders for Logos -->
                    <span
                        class="text-xl font-bold font-display text-[var(--text-tertiary)] hover:text-[var(--text-primary)] dark:hover:text-white transition-colors cursor-default"
                        >Acme Corp</span
                    >
                    <span
                        class="text-xl font-bold font-display text-[var(--text-tertiary)] hover:text-[var(--text-primary)] dark:hover:text-white transition-colors cursor-default"
                        >GlobalBank</span
                    >
                    <span
                        class="text-xl font-bold font-display text-[var(--text-tertiary)] hover:text-[var(--text-primary)] dark:hover:text-white transition-colors cursor-default"
                        >TechFlow</span
                    >
                    <span
                        class="text-xl font-bold font-display text-[var(--text-tertiary)] hover:text-[var(--text-primary)] dark:hover:text-white transition-colors cursor-default"
                        >Innovate</span
                    >
                    <span
                        class="text-xl font-bold font-display text-[var(--text-tertiary)] hover:text-[var(--text-primary)] dark:hover:text-white transition-colors cursor-default"
                        >StarkInd</span
                    >
                </div>
            </div>
        </section>

        <!-- Solutions / Features Grid -->
        <section
            id="services"
            ref="solutionsRef"
            class="py-24 bg-white dark:bg-[var(--surface-primary)] relative scroll-mt-28"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div
                    class="text-center max-w-3xl mx-auto mb-20 section-title opacity-0"
                >
                    <h2
                        class="text-4xl font-bold text-[var(--text-primary)] dark:text-white mb-6 font-landing"
                    >
                        Everything needed to run your <br />
                        <span class="text-[var(--color-landing-primary)]"
                            >digital empire</span
                        >.
                    </h2>
                    <p
                        class="text-xl text-[var(--text-secondary)] dark:text-[var(--text-muted)]"
                    >
                        Replace your disconnected stack with one unified
                        platform designed for speed and clarity.
                    </p>
                </div>

                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                    <Card
                        v-for="(feature, idx) in features"
                        :key="idx"
                        class="feature-card opacity-0 group hover:border-[var(--color-landing-primary)] transition-all duration-300 hover:shadow-lg cursor-pointer bg-[var(--surface-elevated)] dark:bg-[var(--surface-elevated)] dark:border-white/5"
                    >
                        <div class="p-6">
                            <div
                                class="w-12 h-12 rounded-lg bg-[var(--color-landing-surface)] dark:bg-[var(--color-landing-primary)]/10 flex items-center justify-center text-[var(--color-landing-primary)] mb-6 group-hover:scale-110 transition-transform"
                            >
                                <component :is="feature.icon" class="w-6 h-6" />
                            </div>
                            <h3
                                class="text-xl font-bold text-[var(--text-primary)] dark:text-white mb-3 font-landing"
                            >
                                {{ feature.title }}
                            </h3>
                            <p
                                class="text-[var(--text-secondary)] dark:text-[var(--text-secondary)] leading-relaxed"
                            >
                                {{ feature.description }}
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section
            id="pricing"
            ref="pricingRef"
            class="py-24 bg-[var(--surface-secondary)] dark:bg-[var(--surface-secondary)] relative border-t border-[var(--color-neutral-200)] dark:border-white/5 scroll-mt-28"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div
                    class="text-center max-w-3xl mx-auto mb-16 pricing-title opacity-0"
                >
                    <h2
                        class="text-4xl font-bold text-[var(--text-primary)] dark:text-white mb-6 font-landing"
                    >
                        Simple pricing for
                        <span class="text-[var(--color-landing-primary)]"
                            >everyone</span
                        >.
                    </h2>
                    <p
                        class="text-xl text-[var(--text-secondary)] dark:text-[var(--text-muted)]"
                    >
                        Start for free, scale as you grow. No hidden fees.
                    </p>
                </div>

                <div class="grid md:grid-cols-3 gap-8">
                    <div
                        v-for="(plan, idx) in plans"
                        :key="idx"
                        class="pricing-card opacity-0 relative bg-white dark:bg-[var(--surface-elevated)] rounded-2xl p-8 border hover:border-[var(--color-landing-primary)] transition-all duration-300 shadow-sm hover:shadow-xl flex flex-col"
                        :class="
                            plan.highlight
                                ? 'border-[var(--color-landing-primary)] shadow-md ring-1 ring-[var(--color-landing-primary)]'
                                : 'border-[var(--color-neutral-200)] dark:border-white/10'
                        "
                    >
                        <div
                            v-if="plan.highlight"
                            class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-[var(--color-landing-primary)] text-white px-4 py-1 rounded-full text-sm font-bold shadow-lg"
                        >
                            Most Popular
                        </div>
                        <h3
                            class="text-2xl font-bold text-[var(--text-primary)] dark:text-white mb-2"
                        >
                            {{ plan.name }}
                        </h3>
                        <div
                            class="text-4xl font-bold mb-4 font-landing dark:text-white"
                        >
                            {{ plan.price
                            }}<span
                                class="text-lg font-normal text-[var(--text-muted)]"
                                >/mo</span
                            >
                        </div>
                        <p class="text-[var(--text-secondary)] mb-8">
                            {{ plan.description }}
                        </p>

                        <ul class="space-y-4 mb-8 flex-1 w-full">
                            <li
                                v-for="feat in plan.features"
                                :key="feat"
                                class="flex items-center gap-3 text-[var(--text-secondary)] dark:text-gray-300"
                            >
                                <CheckCircle2
                                    class="w-5 h-5 text-green-500 flex-shrink-0"
                                />
                                <span class="text-sm font-medium">{{
                                    feat
                                }}</span>
                            </li>
                        </ul>

                        <button
                            class="w-full py-3 rounded-xl font-bold transition-all"
                            :class="
                                plan.highlight
                                    ? 'bg-[var(--color-landing-primary)] text-white hover:bg-violet-700 shadow-lg shadow-violet-500/20'
                                    : 'bg-[var(--surface-secondary)] text-[var(--text-primary)] hover:bg-[var(--border-default)] dark:bg-white/5 dark:text-white dark:hover:bg-white/10'
                            "
                        >
                            Choose {{ plan.name }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Reviews / Testimonials -->
        <section
            id="reviews"
            ref="reviewsRef"
            class="py-24 bg-white dark:bg-[var(--surface-primary)] scroll-mt-28"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div
                    class="text-center max-w-3xl mx-auto mb-16 reviews-title opacity-0"
                >
                    <h2
                        class="text-4xl font-bold text-[var(--text-primary)] dark:text-white mb-6 font-landing"
                    >
                        Loved by
                        <span class="text-[var(--color-landing-primary)]"
                            >builders</span
                        >
                        worldwide.
                    </h2>
                </div>

                <div class="grid md:grid-cols-3 gap-8">
                    <div
                        v-for="(review, idx) in reviews"
                        :key="idx"
                        class="review-card opacity-0 bg-[var(--surface-elevated)] p-8 rounded-2xl border border-[var(--color-neutral-100)] dark:border-white/5 shadow-sm hover:shadow-md transition-shadow"
                    >
                        <div class="flex gap-1 text-orange-400 mb-6">
                            <Star
                                v-for="i in 5"
                                :key="i"
                                class="w-5 h-5 fill-current"
                            />
                        </div>
                        <p
                            class="text-lg text-[var(--text-primary)] dark:text-gray-200 mb-6 italic leading-relaxed"
                        >
                            "{{ review.content }}"
                        </p>
                        <div class="flex items-center gap-4">
                            <div
                                class="w-12 h-12 rounded-full bg-[var(--color-landing-secondary)] flex items-center justify-center text-[var(--text-primary)] font-bold text-lg"
                            >
                                {{ review.avatar }}
                            </div>
                            <div>
                                <div
                                    class="font-bold text-[var(--text-primary)] dark:text-white"
                                >
                                    {{ review.author }}
                                </div>
                                <div class="text-sm text-[var(--text-muted)]">
                                    {{ review.role }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Global Scale Section -->
        <section
            ref="scaleRef"
            class="py-24 bg-[var(--surface-primary)] dark:bg-[var(--surface-secondary)] overflow-hidden"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid lg:grid-cols-2 gap-16 items-center">
                    <div class="scale-content opacity-0">
                        <div
                            class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 text-sm font-medium mb-6"
                        >
                            <Globe class="w-4 h-4" />
                            Global Infrastructure
                        </div>
                        <h2
                            class="text-4xl font-bold text-[var(--text-primary)] dark:text-white mb-6 font-landing"
                        >
                            Collaboration without borders.
                        </h2>
                        <p
                            class="text-lg text-[var(--text-secondary)] dark:text-[var(--text-muted)] mb-8"
                        >
                            Whether your team is in New York, London, or Tokyo,
                            WorkSphere keeps everyone in sync with
                            sub-millisecond latency.
                        </p>

                        <ul class="space-y-4 mb-10">
                            <li class="flex items-center gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400 flex-shrink-0"
                                >
                                    <CheckCircle2 class="w-4 h-4" />
                                </div>
                                <span
                                    class="text-[var(--text-primary)] dark:text-gray-200 font-medium"
                                    >99.99% Uptime SLA</span
                                >
                            </li>
                            <li class="flex items-center gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400 flex-shrink-0"
                                >
                                    <CheckCircle2 class="w-4 h-4" />
                                </div>
                                <span
                                    class="text-[var(--text-primary)] dark:text-gray-200 font-medium"
                                    >Enterprise-grade Encryption</span
                                >
                            </li>
                            <li class="flex items-center gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400 flex-shrink-0"
                                >
                                    <CheckCircle2 class="w-4 h-4" />
                                </div>
                                <span
                                    class="text-[var(--text-primary)] dark:text-gray-200 font-medium"
                                    >GDPR & CCPA Compliant</span
                                >
                            </li>
                        </ul>

                        <button
                            class="btn btn-secondary border-[var(--color-neutral-300)] dark:border-white/20 dark:text-white dark:hover:bg-white/10 hover:border-[var(--color-landing-primary)] hover:text-[var(--color-landing-primary)] cursor-pointer"
                        >
                            Learn about Security
                        </button>
                    </div>

                    <div class="relative scale-image opacity-0">
                        <!-- Abstract Map Visualization Placeholder -->
                        <div
                            class="aspect-square bg-[var(--color-landing-surface)] dark:bg-[var(--color-landing-primary)]/5 rounded-full opacity-50 absolute inset-0 blur-3xl animate-pulse-slow"
                        ></div>
                        <div
                            class="relative z-10 bg-white dark:bg-[var(--surface-elevated)] border border-[var(--color-neutral-200)] dark:border-white/10 rounded-2xl shadow-2xl p-8"
                        >
                            <img
                                src="/doc/screenshots/analytics.png"
                                alt="Global Analytics"
                                class="rounded-lg shadow-sm"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section
            class="py-32 bg-[var(--text-primary)] dark:bg-black relative overflow-hidden"
        >
            <div class="absolute inset-0 opacity-10">
                <div
                    class="absolute top-0 right-0 w-[800px] h-[800px] bg-[var(--color-landing-primary)] rounded-full blur-[150px] -translate-y-1/2 translate-x-1/2"
                ></div>
            </div>

            <div
                class="relative max-w-4xl mx-auto px-4 text-center"
                ref="ctaRef"
            >
                <h2
                    class="text-4xl md:text-5xl font-bold text-white mb-8 font-landing tracking-tight opacity-0"
                >
                    Ready to transform how you work?
                </h2>
                <p
                    class="text-xl text-white/70 mb-12 max-w-2xl mx-auto opacity-0"
                >
                    Join thousands of high-performing teams who use
                    {{ appConfig.name }} to build the future.
                </p>
                <div
                    class="flex flex-col sm:flex-row items-center justify-center gap-4 opacity-0"
                >
                    <RouterLink to="/auth/login" class="w-full sm:w-auto">
                        <button
                            class="btn bg-white text-neutral-900 hover:bg-gray-100 w-full sm:w-auto px-10 py-4 text-lg font-bold rounded-xl shadow-xl transition-transform hover:-translate-y-1 cursor-pointer"
                        >
                            Get Started Now
                        </button>
                    </RouterLink>
                    <button
                        class="btn bg-transparent border border-white/20 text-white hover:bg-white/10 w-full sm:w-auto px-10 py-4 text-lg font-medium rounded-xl cursor-pointer"
                    >
                        Contact Sales
                    </button>
                </div>
                <p class="mt-8 text-sm text-white/40 opacity-0">
                    No credit card required · 14-day free trial · Cancel anytime
                </p>
            </div>
        </section>
    </PublicLayout>
</template>

<style scoped>
/* Scoped styles mainly for animation helpers */
.font-landing {
    font-family: var(--font-landing);
}

.animate-pulse-slow {
    animation: pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 0.2;
    }
    50% {
        opacity: 0.15;
    }
}

.animate-bounce-slow {
    animation: bounce 3s infinite;
}

@keyframes bounce {
    0%,
    100% {
        transform: translateY(-5%);
        animation-timing-function: cubic-bezier(0.8, 0, 1, 1);
    }
    50% {
        transform: translateY(0);
        animation-timing-function: cubic-bezier(0, 0, 0.2, 1);
    }
}
</style>
