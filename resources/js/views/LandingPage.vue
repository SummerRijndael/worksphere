<script setup lang="ts">
import { ref, onMounted, onUnmounted } from "vue";
import { RouterLink } from "vue-router";
import { Button, Card, PageLoader } from "@/components/ui";
import PublicLayout from "@/layouts/PublicLayout.vue";
import { animate, stagger } from "animejs";
import useRecaptcha from "@/composables/useRecaptcha";
import axios from "axios";
import {
    FolderKanban,
    Users,
    BarChart3,
    Shield,
    Zap,
    Clock,
    Check,
    Star,
    Mail,
    MapPin,
    Phone,
    ArrowRight,
} from "lucide-vue-next";
import { appConfig } from "@/config/app";

const isLoading = ref(true);

// Animation refs
const heroRef = ref<HTMLElement | null>(null);
const servicesRef = ref<HTMLElement | null>(null);
const pricingRef = ref<HTMLElement | null>(null);
const reviewsRef = ref<HTMLElement | null>(null);
const contactRef = ref<HTMLElement | null>(null);
const ctaRef = ref<HTMLElement | null>(null);

// Store observers for cleanup
const observers: IntersectionObserver[] = [];

// Services data
const services = [
    {
        icon: FolderKanban,
        title: "Project Management",
        description:
            "Organize and track projects with intuitive boards, timelines, and milestones.",
    },
    {
        icon: Users,
        title: "Team Collaboration",
        description:
            "Work together seamlessly with real-time updates and shared workspaces.",
    },
    {
        icon: BarChart3,
        title: "Analytics & Insights",
        description:
            "Make data-driven decisions with comprehensive reporting and analytics.",
    },
    {
        icon: Shield,
        title: "Enterprise Security",
        description:
            "Bank-grade security with SSO, 2FA, and role-based access controls.",
    },
    {
        icon: Zap,
        title: "Automation",
        description:
            "Automate repetitive tasks and workflows to boost productivity.",
    },
    {
        icon: Clock,
        title: "Time Tracking",
        description:
            "Track time spent on tasks and generate detailed timesheets.",
    },
];

// Pricing tiers
const pricingTiers = [
    {
        name: "Starter",
        price: "0",
        period: "forever",
        description: "Perfect for individuals and small teams getting started.",
        features: [
            "Up to 5 team members",
            "10 projects",
            "Basic analytics",
            "Email support",
            "5GB storage",
        ],
        cta: "Get Started Free",
        popular: false,
    },
    {
        name: "Professional",
        price: "29",
        period: "per user/month",
        description: "For growing teams that need more power and flexibility.",
        features: [
            "Unlimited team members",
            "Unlimited projects",
            "Advanced analytics",
            "Priority support",
            "100GB storage",
            "Custom workflows",
            "API access",
        ],
        cta: "Start Free Trial",
        popular: true,
    },
    {
        name: "Enterprise",
        price: "Custom",
        period: "contact us",
        description: "For large organizations with custom requirements.",
        features: [
            "Everything in Professional",
            "Dedicated account manager",
            "Custom integrations",
            "On-premise deployment",
            "Unlimited storage",
            "SLA guarantee",
            "24/7 phone support",
        ],
        cta: "Contact Sales",
        popular: false,
    },
];

// Testimonials
const testimonials = [
    {
        quote: `${appConfig.name} transformed how our team works. We've seen a 40% increase in productivity since switching.`,
        author: "Sarah Chen",
        role: "Engineering Manager",
        company: "TechFlow Inc.",
    },
    {
        quote: "The best project management tool we've ever used. The interface is intuitive and the features are powerful.",
        author: "Michael Rodriguez",
        role: "Product Director",
        company: "Innovate Labs",
    },
    {
        quote: "Finally, a tool that our entire organization can use. From marketing to engineering, everyone loves it.",
        author: "Emily Watson",
        role: "COO",
        company: "ScaleUp Ventures",
    },
];

import RecaptchaChallengeModal from "@/components/common/RecaptchaChallengeModal.vue";

// Contact form
const { executeRecaptcha } = useRecaptcha();
const showChallenge = ref(false);
// pendingAction removed

const contactForm = ref({
    name: "",
    email: "",
    message: "",
});
const contactLoading = ref(false);

async function handleContactSubmit() {
    contactLoading.value = true;
    try {
        const token = await executeRecaptcha("contact");
        if (!token) {
            console.error("ReCAPTCHA failed");
            return;
        }

        await axios.post("/api/contact", {
            ...contactForm.value,
            recaptcha_token: token,
        });

        // Reset form and show success (simple alert for now)
        contactForm.value = { name: "", email: "", message: "" };
        alert("Message sent successfully!");
    } catch (error: any) {
        if (error.response?.data?.requires_challenge) {
            showChallenge.value = true;
            return;
        }
        console.error("Contact submission failed:", error);
        alert("Failed to send message.");
    } finally {
        contactLoading.value = false;
    }
}

async function handleChallengeVerified(v2Token: string) {
    showChallenge.value = false;
    contactLoading.value = true;
    try {
        await axios.post("/api/contact", {
            ...contactForm.value,
            recaptcha_token: "fallback-initiated", // Backend will ignore this if v2 token is present
            recaptcha_v2_token: v2Token,
        });

        contactForm.value = { name: "", email: "", message: "" };
        alert("Message sent successfully!");
    } catch (error) {
        console.error("Contact submission failed (fallback):", error);
        alert("Failed to send message.");
    } finally {
        contactLoading.value = false;
    }
}

// Scroll animation helper
function createScrollAnimation(
    element: HTMLElement,
    target: string | Element | NodeListOf<Element> | null,
    options: Record<string, unknown>,
    threshold = 0.15
) {
    if (!target) return;
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animate(target, options);
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold, rootMargin: "0px 0px -50px 0px" }
    );
    observer.observe(element);
    observers.push(observer);
}

// Initialize animations
onMounted(() => {
    // Dismiss loader after brief delay
    setTimeout(() => {
        isLoading.value = false;
    }, 1200);

    // Hero section - immediate animation
    if (heroRef.value) {
        const badge = heroRef.value.querySelector(".hero-badge");
        const headline = heroRef.value.querySelector(".hero-headline");
        const subtitle = heroRef.value.querySelector(".hero-subtitle");
        const ctas = heroRef.value.querySelector(".hero-ctas");
        const socialProof = heroRef.value.querySelector(".hero-social-proof");
        const floats = heroRef.value.querySelectorAll(".hero-float");

        // Animate badge
        if (badge) {
            animate(badge, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
                delay: 200,
            });
        }

        // Animate headline
        if (headline) {
            animate(headline, {
                opacity: [0, 1],
                translateY: [50, 0],
                duration: 1000,
                easing: "easeOutExpo",
                delay: 400,
            });
        }

        // Animate subtitle
        if (subtitle) {
            animate(subtitle, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
                delay: 600,
            });
        }

        // Animate CTAs
        if (ctas) {
            animate(ctas, {
                opacity: [0, 1],
                translateY: [30, 0],
                duration: 800,
                easing: "easeOutExpo",
                delay: 800,
            });
        }

        // Animate social proof
        if (socialProof) {
            animate(socialProof, {
                opacity: [0, 1],
                translateY: [20, 0],
                duration: 800,
                easing: "easeOutExpo",
                delay: 1000,
            });
        }

        // Floating animation for background shapes
        if (floats.length > 0) {
            animate(floats, {
                translateY: [-15, 15],
                duration: 3000,
                easing: "easeInOutSine",
                alternate: true,
                loop: true,
            });
        }
    }

    // Services section
    if (servicesRef.value) {
        createScrollAnimation(
            servicesRef.value,
            servicesRef.value.querySelector(".section-header"),
            {
                opacity: [0, 1],
                translateY: [40, 0],
                duration: 800,
                easing: "easeOutExpo",
            }
        );

        createScrollAnimation(
            servicesRef.value,
            servicesRef.value.querySelectorAll(".service-card"),
            {
                opacity: [0, 1],
                translateY: [60, 0],
                scale: [0.9, 1],
                duration: 800,
                delay: stagger(100, { start: 200 }),
                easing: "easeOutExpo",
            }
        );
    }

    // Pricing section
    if (pricingRef.value) {
        createScrollAnimation(
            pricingRef.value,
            pricingRef.value.querySelector(".section-header"),
            {
                opacity: [0, 1],
                translateY: [40, 0],
                duration: 800,
                easing: "easeOutExpo",
            }
        );

        createScrollAnimation(
            pricingRef.value,
            pricingRef.value.querySelectorAll(".pricing-card"),
            {
                opacity: [0, 1],
                translateY: [80, 0],
                scale: [0.85, 1],
                duration: 1000,
                delay: stagger(150, { start: 200 }),
                easing: "easeOutExpo",
            }
        );
    }

    // Reviews section
    if (reviewsRef.value) {
        createScrollAnimation(
            reviewsRef.value,
            reviewsRef.value.querySelector(".section-header"),
            {
                opacity: [0, 1],
                translateY: [40, 0],
                duration: 800,
                easing: "easeOutExpo",
            }
        );

        createScrollAnimation(
            reviewsRef.value,
            reviewsRef.value.querySelectorAll(".review-card"),
            {
                opacity: [0, 1],
                translateX: [-50, 0],
                duration: 900,
                delay: stagger(120, { start: 200 }),
                easing: "easeOutExpo",
            }
        );
    }

    // Contact section
    if (contactRef.value) {
        createScrollAnimation(
            contactRef.value,
            contactRef.value.querySelector(".contact-info"),
            {
                opacity: [0, 1],
                translateX: [-60, 0],
                duration: 900,
                easing: "easeOutExpo",
            }
        );

        createScrollAnimation(
            contactRef.value,
            contactRef.value.querySelector(".contact-form"),
            {
                opacity: [0, 1],
                translateX: [60, 0],
                duration: 900,
                delay: 200,
                easing: "easeOutExpo",
            }
        );
    }

    // CTA section
    if (ctaRef.value) {
        createScrollAnimation(
            ctaRef.value,
            ctaRef.value.querySelectorAll(".cta-content > *"),
            {
                opacity: [0, 1],
                translateY: [40, 0],
                duration: 800,
                delay: stagger(150),
                easing: "easeOutExpo",
            }
        );
    }
});

onUnmounted(() => {
    observers.forEach((observer) => observer.disconnect());
});
</script>

<template>
    <!-- Page Loader -->
    <PageLoader :show="isLoading" />

    <PublicLayout>
        <!-- Hero Section -->
        <section ref="heroRef" class="relative overflow-hidden pt-16">
            <!-- Animated background elements - More vibrant & complex -->
            <div class="absolute inset-0 bg-[var(--surface-primary)]">
                <!-- Main gradient mesh -->
                <div class="absolute top-0 inset-x-0 h-[600px] bg-gradient-to-b from-[var(--color-primary-50)]/50 to-transparent dark:from-[var(--color-primary-900)]/20 dark:to-transparent" />
                
                <!-- Floating orbs with better colors -->
                <div
                    class="hero-float absolute top-[-10%] right-[-5%] w-[500px] h-[500px] bg-[var(--interactive-primary)]/20 rounded-full blur-[100px] dark:bg-[var(--interactive-primary)]/10"
                />
                <div
                    class="hero-float absolute top-[20%] left-[-10%] w-[400px] h-[400px] bg-purple-500/20 rounded-full blur-[100px] dark:bg-purple-900/20"
                    style="animation-delay: -2s"
                />
            </div>

            <div
                class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-32 lg:py-40 flex flex-col items-center text-center"
            >
                <div class="max-w-4xl mx-auto flex flex-col items-center">
                    <!-- Badge - Glassmorphism style -->
                    <div
                        class="hero-badge opacity-0 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/50 dark:bg-white/5 backdrop-blur-md shadow-sm border border-[var(--border-default)] text-sm font-medium text-[var(--text-secondary)] mb-8 hover:bg-[var(--surface-elevated)] transition-colors cursor-default"
                    >
                        <span class="flex h-2 w-2 rounded-full bg-green-500"></span>
                        Trusted by 10,000+ teams worldwide
                    </div>

                    <!-- Headline - Better gradient and tight tracking -->
                    <h1
                        class="hero-headline opacity-0 text-5xl sm:text-6xl md:text-7xl font-bold text-[var(--text-primary)] tracking-tight mb-8 leading-[1.1]"
                    >
                        Manage Projects with
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--interactive-primary)] to-purple-600 dark:to-purple-400">
                            Clarity & Speed
                        </span>
                    </h1>

                    <!-- Subtitle - Improved readability -->
                    <p
                        class="hero-subtitle opacity-0 text-xl text-[var(--text-secondary)] max-w-2xl mx-auto mb-12 leading-relaxed"
                    >
                        {{ appConfig.name }} brings your team's work together in one shared
                        space. Plan, track, and deliver projects of any size
                        with powerful tools designed for modern teams.
                    </p>

                    <!-- CTAs - Better shadows and hover effects -->
                    <div
                        class="hero-ctas opacity-0 flex flex-col sm:flex-row items-center justify-center gap-4 w-full sm:w-auto"
                    >
                        <RouterLink to="/auth/login" class="w-full sm:w-auto">
                            <Button
                                size="lg"
                                class="w-full sm:w-auto shadow-lg shadow-[var(--interactive-primary)]/25 px-8 h-12 text-base font-semibold transition-all hover:scale-105 active:scale-95"
                            >
                                Start for Free
                                <ArrowRight
                                    class="h-5 w-5 ml-2 transition-transform group-hover:translate-x-1"
                                />
                            </Button>
                        </RouterLink>
                        <a href="#services" class="w-full sm:w-auto">
                            <Button variant="outline" size="lg" class="w-full sm:w-auto px-8 h-12 text-base bg-white/50 dark:bg-black/20 backdrop-blur-sm border-[var(--border-default)] hover:bg-[var(--surface-elevated)] active:scale-95 transition-all">
                                Learn More
                            </Button>
                        </a>
                    </div>

                    <!-- Social proof - Refined spacing -->
                    <div
                        class="hero-social-proof opacity-0 mt-16 flex flex-col sm:flex-row items-center justify-center gap-6 text-sm font-medium text-[var(--text-muted)]"
                    >
                        <div class="flex items-center gap-2 px-4 py-2 rounded-full bg-[var(--surface-secondary)]/50 backdrop-blur-sm border border-transparent hover:border-[var(--border-default)] transition-all">
                            <div class="flex pb-0.5">
                                <Star
                                    v-for="i in 5"
                                    :key="i"
                                    class="h-3.5 w-3.5 text-yellow-400 fill-current"
                                />
                            </div>
                            <span class="text-[var(--text-primary)]">4.9/5 rating</span>
                        </div>
                        <div class="hidden sm:block w-1.5 h-1.5 rounded-full bg-[var(--border-default)]" />
                        <span>No credit card required</span>
                        <div class="hidden sm:block w-1.5 h-1.5 rounded-full bg-[var(--border-default)]" />
                        <span>Free 14-day trial</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Services Section -->
        <section
            id="services"
            ref="servicesRef"
            class="py-24 bg-[var(--surface-secondary)]"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Section header -->
                <div
                    class="section-header opacity-0 text-center max-w-3xl mx-auto mb-16"
                >
                    <h2
                        class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-4"
                    >
                        Everything You Need to Succeed
                    </h2>
                    <p class="text-lg text-[var(--text-secondary)]">
                        Powerful features designed to help your team work
                        smarter, not harder.
                    </p>
                </div>

                <!-- Services grid -->
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <Card
                        v-for="service in services"
                        :key="service.title"
                        padding="lg"
                        hover
                        class="service-card opacity-0 group"
                    >
                        <div
                            class="h-12 w-12 rounded-xl bg-gradient-to-br from-[var(--color-primary-500)] to-[var(--color-primary-600)] flex items-center justify-center mb-5 group-hover:scale-110 group-hover:rotate-3 transition-all duration-300"
                        >
                            <component
                                :is="service.icon"
                                class="h-6 w-6 text-white"
                            />
                        </div>
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-2"
                        >
                            {{ service.title }}
                        </h3>
                        <p class="text-[var(--text-secondary)]">
                            {{ service.description }}
                        </p>
                    </Card>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section id="pricing" ref="pricingRef" class="py-24">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Section header -->
                <div
                    class="section-header opacity-0 text-center max-w-3xl mx-auto mb-16"
                >
                    <h2
                        class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-4"
                    >
                        Simple, Transparent Pricing
                    </h2>
                    <p class="text-lg text-[var(--text-secondary)]">
                        Choose the plan that's right for your team. All plans
                        include a 14-day free trial.
                    </p>
                </div>

                <!-- Pricing cards -->
                <div class="grid lg:grid-cols-3 gap-8 max-w-5xl mx-auto">
                    <Card
                        v-for="tier in pricingTiers"
                        :key="tier.name"
                        padding="none"
                        :class="[
                            'pricing-card opacity-0 relative overflow-visible',
                            tier.popular &&
                                'ring-2 ring-[var(--interactive-primary)] lg:scale-105',
                        ]"
                    >
                        <!-- Popular badge -->
                        <div
                            v-if="tier.popular"
                            class="absolute -top-4 left-1/2 -translate-x-1/2 px-4 py-1 rounded-full bg-[var(--interactive-primary)] text-white text-sm font-medium shadow-lg"
                        >
                            Most Popular
                        </div>

                        <div class="p-6 sm:p-8">
                            <h3
                                class="text-xl font-semibold text-[var(--text-primary)] mb-2"
                            >
                                {{ tier.name }}
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] mb-6"
                            >
                                {{ tier.description }}
                            </p>

                            <div class="mb-6">
                                <span
                                    class="text-4xl font-bold text-[var(--text-primary)]"
                                >
                                    {{ tier.price === "Custom" ? "" : "$"
                                    }}{{ tier.price }}
                                </span>
                                <span class="text-[var(--text-muted)] ml-2">
                                    {{ tier.period }}
                                </span>
                            </div>

                            <RouterLink to="/auth/login">
                                <Button
                                    :variant="
                                        tier.popular ? 'primary' : 'outline'
                                    "
                                    full-width
                                    class="mb-6"
                                >
                                    {{ tier.cta }}
                                </Button>
                            </RouterLink>

                            <ul class="space-y-3">
                                <li
                                    v-for="feature in tier.features"
                                    :key="feature"
                                    class="flex items-start gap-3"
                                >
                                    <Check
                                        class="h-5 w-5 text-[var(--color-success)] shrink-0 mt-0.5"
                                    />
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >{{ feature }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </Card>
                </div>
            </div>
        </section>

        <!-- Reviews Section -->
        <section
            id="reviews"
            ref="reviewsRef"
            class="py-24 bg-[var(--surface-secondary)]"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Section header -->
                <div
                    class="section-header opacity-0 text-center max-w-3xl mx-auto mb-16"
                >
                    <h2
                        class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-4"
                    >
                        Loved by Teams Everywhere
                    </h2>
                    <p class="text-lg text-[var(--text-secondary)]">
                        See what our customers have to say about {{ appConfig.name }}.
                    </p>
                </div>

                <!-- Testimonials grid -->
                <div class="grid md:grid-cols-3 gap-6">
                    <Card
                        v-for="testimonial in testimonials"
                        :key="testimonial.author"
                        padding="lg"
                        class="review-card opacity-0 flex flex-col hover:shadow-xl transition-shadow duration-300"
                    >
                        <!-- Stars -->
                        <div class="flex gap-1 mb-4">
                            <Star
                                v-for="i in 5"
                                :key="i"
                                class="h-5 w-5 text-yellow-500 fill-current"
                            />
                        </div>

                        <!-- Quote -->
                        <blockquote
                            class="text-[var(--text-primary)] mb-6 flex-1"
                        >
                            "{{ testimonial.quote }}"
                        </blockquote>

                        <!-- Author -->
                        <div class="flex items-center gap-3">
                            <div
                                class="h-10 w-10 rounded-full bg-gradient-to-br from-[var(--color-primary-400)] to-[var(--color-primary-600)] flex items-center justify-center text-white font-semibold"
                            >
                                {{ testimonial.author.charAt(0) }}
                            </div>
                            <div>
                                <p
                                    class="font-medium text-[var(--text-primary)]"
                                >
                                    {{ testimonial.author }}
                                </p>
                                <p class="text-sm text-[var(--text-muted)]">
                                    {{ testimonial.role }},
                                    {{ testimonial.company }}
                                </p>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section id="contact" ref="contactRef" class="py-24">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid lg:grid-cols-2 gap-12 lg:gap-16">
                    <!-- Contact info -->
                    <div class="contact-info opacity-0">
                        <h2
                            class="text-3xl sm:text-4xl font-bold text-[var(--text-primary)] mb-4"
                        >
                            Get in Touch
                        </h2>
                        <p class="text-lg text-[var(--text-secondary)] mb-8">
                            Have questions? We'd love to hear from you. Send us
                            a message and we'll respond as soon as possible.
                        </p>

                        <div class="space-y-6">
                            <div class="flex items-start gap-4 group">
                                <div
                                    class="h-10 w-10 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center shrink-0 group-hover:bg-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                >
                                    <Mail
                                        class="h-5 w-5 text-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="font-medium text-[var(--text-primary)]"
                                    >
                                        Email
                                    </p>
                                    <a
                                        href="mailto:hello@coresync.io"
                                        class="text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] transition-colors"
                                    >
                                        hello@coresync.io
                                    </a>
                                </div>
                            </div>

                            <div class="flex items-start gap-4 group">
                                <div
                                    class="h-10 w-10 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center shrink-0 group-hover:bg-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                >
                                    <Phone
                                        class="h-5 w-5 text-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="font-medium text-[var(--text-primary)]"
                                    >
                                        Phone
                                    </p>
                                    <a
                                        href="tel:+1-555-123-4567"
                                        class="text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] transition-colors"
                                    >
                                        +1 (555) 123-4567
                                    </a>
                                </div>
                            </div>

                            <div class="flex items-start gap-4 group">
                                <div
                                    class="h-10 w-10 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center shrink-0 group-hover:bg-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                >
                                    <MapPin
                                        class="h-5 w-5 text-[var(--interactive-primary)] group-hover:text-white transition-colors"
                                    />
                                </div>
                                <div>
                                    <p
                                        class="font-medium text-[var(--text-primary)]"
                                    >
                                        Office
                                    </p>
                                    <p class="text-[var(--text-secondary)]">
                                        100 Innovation Drive<br />
                                        San Francisco, CA 94107
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact form -->
                    <Card padding="lg" class="contact-form opacity-0">
                        <form
                            @submit.prevent="handleContactSubmit"
                            class="space-y-5"
                        >
                            <div>
                                <label
                                    class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                                >
                                    Name
                                </label>
                                <input
                                    v-model="contactForm.name"
                                    type="text"
                                    placeholder="Your name"
                                    class="input"
                                    required
                                />
                            </div>

                            <div>
                                <label
                                    class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                                >
                                    Email
                                </label>
                                <input
                                    v-model="contactForm.email"
                                    type="email"
                                    placeholder="you@example.com"
                                    class="input"
                                    required
                                />
                            </div>

                            <div>
                                <label
                                    class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                                >
                                    Message
                                </label>
                                <textarea
                                    v-model="contactForm.message"
                                    rows="4"
                                    placeholder="How can we help?"
                                    class="input resize-none"
                                    required
                                />
                            </div>

                            <Button
                                type="submit"
                                full-width
                                class="group"
                                :loading="contactLoading"
                            >
                                Send Message
                                <ArrowRight
                                    class="h-4 w-4 group-hover:translate-x-1 transition-transform"
                                />
                            </Button>
                        </form>
                    </Card>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section
            ref="ctaRef"
            class="py-24 bg-gradient-to-br from-[var(--color-primary-500)] to-[var(--color-primary-700)] relative overflow-hidden"
        >
            <!-- Decorative elements -->
            <div class="absolute inset-0 opacity-10">
                <div
                    class="absolute top-0 left-0 w-64 h-64 bg-white rounded-full blur-3xl"
                />
                <div
                    class="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full blur-3xl"
                />
            </div>

            <div
                class="cta-content relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center"
            >
                <h2
                    class="opacity-0 text-3xl sm:text-4xl font-bold text-white mb-4"
                >
                    Ready to Transform Your Workflow?
                </h2>
                <p class="opacity-0 text-lg text-white mb-8">
                    Join thousands of teams already using {{ appConfig.name }} to manage
                    their projects more effectively.
                </p>
                <div
                    class="opacity-0 flex flex-col sm:flex-row items-center justify-center gap-4"
                >
                    <RouterLink to="/auth/login">
                        <Button
                            size="lg"
                            variant="secondary"
                            class="!bg-white !text-[var(--color-primary-700)] hover:!bg-gray-100 px-8 shadow-xl font-semibold"
                        >
                            Get Started Free
                        </Button>
                    </RouterLink>
                    <a href="#contact">
                        <Button
                            size="lg"
                            variant="outline"
                            class="border-white/30 text-white hover:bg-white/10 px-8"
                        >
                            Contact Sales
                        </Button>
                    </a>
                </div>
            </div>
        </section>
    </PublicLayout>

    <RecaptchaChallengeModal
        :show="showChallenge"
        @close="showChallenge = false"
        @verified="handleChallengeVerified"
    />
</template>
