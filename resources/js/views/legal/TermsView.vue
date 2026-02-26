<script setup>
import { appConfig } from "@/config/app";
import { useAuthStore } from "@/stores/auth";
import { computed, onMounted } from "vue";

const authStore = useAuthStore();
onMounted(() => {
    if (!authStore.publicConfig) {
        authStore.fetchPublicConfig();
    }
});

const lastUpdated = "February 3, 2026";
const companyName = appConfig.name;
const supportEmail = computed(
    () => authStore.publicConfig?.contact?.legal || "legal@worksphere.com",
);
</script>

<template>
    <article class="prose prose-slate dark:prose-invert max-w-none">
        <header class="not-prose mb-12">
            <h1 class="text-3xl font-bold text-[var(--text-primary)] mb-2">
                Terms of Service
            </h1>
            <p class="text-[var(--text-muted)]">
                Last updated: {{ lastUpdated }}
            </p>
        </header>

        <section class="space-y-8">
            <!-- Beta Notice -->
            <div
                class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg"
            >
                <h3
                    class="text-lg font-semibold text-amber-800 dark:text-amber-200 mb-2 flex items-center gap-2"
                >
                    ⚠️ Beta Build Notice
                </h3>
                <p
                    class="text-amber-700 dark:text-amber-300 text-sm leading-relaxed"
                >
                    Please be advised that this application is currently in an
                    <strong>Beta Build Stage</strong>. Many features are still
                    in a demonstration or active development state.
                    Functionality may be limited, unstable, or subject to
                    significant changes or removal without prior notice. Data
                    persistence and backward compatibility are not guaranteed
                    during this phase.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    1. Acceptance of Terms
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    By accessing or using {{ companyName }} ("the Service"), you
                    agree to be bound by these Terms of Service ("Terms"). You
                    acknowledge the Beta nature of the Service as described
                    above. If you disagree with any part of the terms, you may
                    not access the Service. These Terms apply to all visitors,
                    users, and others who access or use the Service.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    2. Description of Service
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    {{ companyName }} is a comprehensive project management and
                    team collaboration platform.
                    <strong>The Service is currently in an Beta phase.</strong>
                    This means that features and capabilities are rapidly
                    evolving. Tools for task management, project tracking, team
                    communication, and analytics are provided for testing and
                    evaluation purposes. The Service is provided "as is" and we
                    expressly reserve the right to modify, suspend, remove, or
                    discontinue any part of the Service, including specific
                    features or data, at any time without liability.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    3. User Accounts
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    When you create an account with us, you must provide
                    accurate, complete, and current information. You are
                    responsible for:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)]"
                >
                    <li>
                        Maintaining the security of your account and password
                    </li>
                    <li>All activities that occur under your account</li>
                    <li>
                        Notifying us immediately of any unauthorized use of your
                        account
                    </li>
                    <li>
                        Ensuring your account information remains accurate and
                        up-to-date
                    </li>
                </ul>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    You must be at least 13 years old to use the Service. By
                    using the Service, you represent that you meet this
                    requirement.
                </p>

                <div class="mt-6 space-y-4">
                    <div
                        class="p-4 bg-gray-50 dark:bg-gray-800 rounded-md border border-gray-100 dark:border-gray-700"
                    >
                        <h4
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            3.1. Support & Account Recovery
                        </h4>
                        <p class="text-sm text-[var(--text-secondary)]">
                            <strong>Free Tier Users:</strong> Support for free
                            accounts is limited to self-service tools (e.g.,
                            password reset). We do not offer manual account
                            recovery or ownership verification for free users.
                            If you lose access to your credentials and cannot
                            recover them via self-service means, you may lose
                            access to your account permanently.
                        </p>
                    </div>

                    <div
                        class="p-4 bg-gray-50 dark:bg-gray-800 rounded-md border border-gray-100 dark:border-gray-700"
                    >
                        <h4
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            3.2. Account Disputes
                        </h4>
                        <p class="text-sm text-[var(--text-secondary)]">
                            In the event of a dispute over account ownership or
                            control (e.g., between business partners or team
                            members),
                            {{ companyName }} will not act as an arbiter or
                            judge. You agree that you must resolve such disputes
                            yourselves. We reserve the right to
                            <strong
                                >suspend the account and all associated data
                                indefinitely...</strong
                            >
                            until we receive joint written instructions resolved
                            to our satisfaction or a valid court order.
                        </p>
                    </div>
                </div>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    4. Data Export
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    You have the right to request a copy of the content you have
                    created (e.g., tasks, tickets, comments). This export is
                    limited to your User Content and does not include:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)]"
                >
                    <li>Platform metadata, configuration, or system logs</li>
                    <li>
                        Proprietary app data (e.g., roles, permissions
                        architecture)
                    </li>
                    <li>
                        Data belonging to other users or organizations to which
                        you do not have admin rights
                    </li>
                </ul>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    5. Acceptable Use
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    You agree not to use the Service to:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)]"
                >
                    <li>Violate any applicable laws or regulations</li>
                    <li>
                        Infringe upon the rights of others, including
                        intellectual property rights
                    </li>
                    <li>
                        Transmit any harmful, threatening, abusive, or
                        defamatory content
                    </li>
                    <li>
                        Distribute malware, viruses, or other harmful software
                    </li>
                    <li>
                        Attempt to gain unauthorized access to any systems or
                        networks
                    </li>
                    <li>
                        Interfere with or disrupt the integrity or performance
                        of the Service
                    </li>
                    <li>Collect or harvest user data without consent</li>
                    <li>
                        Use the Service for any illegal or unauthorized purpose
                    </li>
                    <li>
                        Impersonate any person or entity or misrepresent your
                        affiliation
                    </li>
                </ul>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    6. User Content
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    You retain ownership of any content you submit, post, or
                    display on or through the Service ("User Content"). By
                    submitting User Content, you grant us a worldwide,
                    non-exclusive, royalty-free license to use, reproduce,
                    modify, and display such content solely for the purpose of
                    operating and providing the Service.
                </p>
                <div
                    class="mt-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-md border border-gray-100 dark:border-gray-700"
                >
                    <h4 class="font-semibold text-[var(--text-primary)] mb-2">
                        6.1. File Uploads & Monitoring Disclaimer
                    </h4>
                    <p class="text-sm text-[var(--text-secondary)] mb-2">
                        You acknowledge that {{ companyName }} acts as a passive
                        conduit for the storage and transmission of User
                        Content.
                        <strong
                            >We do not actively monitor, review, or edit User
                            Content or uploaded files.</strong
                        >
                    </p>
                    <p class="text-sm text-[var(--text-secondary)]">
                        You are solely responsible for all files you upload. You
                        agree not to upload sensitive or regulated data
                        (including but not limited to data subject to GDPR,
                        HIPAA, or patent protections) unless you have the
                        necessary rights and have verified that the Service's
                        security controls meet your compliance obligations.
                        {{ companyName }} disclaims all liability for any
                        sensitive data uploaded to the Service.
                    </p>
                </div>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    You represent and warrant that you own or have the necessary
                    rights to your User Content and that it does not violate any
                    third-party rights (including patents, copyrights, or trade
                    secrets) or applicable laws.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    7. Intellectual Property
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    The Service and its original content (excluding User
                    Content), features, and functionality are and will remain
                    the exclusive property of {{ companyName }} and its
                    licensors. The Service is protected by copyright, trademark,
                    and other laws. Our trademarks may not be used in connection
                    with any product or service without prior written consent.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    8. Payment and Billing
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    If you subscribe to a paid plan:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)]"
                >
                    <li>
                        You agree to pay all applicable fees as described at the
                        time of purchase
                    </li>
                    <li>
                        Payments are non-refundable except as required by law or
                        as explicitly stated
                    </li>
                    <li>We may change our prices with 30 days' notice</li>
                    <li>
                        You authorize us to charge your payment method on a
                        recurring basis
                    </li>
                    <li>
                        Failure to pay may result in suspension or termination
                        of your account
                    </li>
                </ul>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    9. Termination
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    We may terminate or suspend your account immediately,
                    without prior notice, for any reason, including:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)]"
                >
                    <li>Breach of these Terms</li>
                    <li>Violation of applicable laws</li>
                    <li>
                        Conduct that we determine is harmful to other users or
                        the Service
                    </li>
                    <li>Extended periods of inactivity</li>
                </ul>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    You may terminate your account at any time by contacting us
                    or using the account deletion feature in your settings. Upon
                    termination, your right to use the Service will immediately
                    cease.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    10. Disclaimer of Warranties
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    THE SERVICE IS PROVIDED ON AN "AS IS" AND "AS AVAILABLE"
                    BASIS WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR
                    IMPLIED, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF
                    MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE,
                    NON-INFRINGEMENT, OR COURSE OF PERFORMANCE. WE DO NOT
                    WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, SECURE, OR
                    ERROR-FREE, OR THAT DEFECTS WILL BE CORRECTED.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    11. Limitation of Liability
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    TO THE MAXIMUM EXTENT PERMITTED BY LAW,
                    {{ companyName.toUpperCase() }} SHALL NOT BE LIABLE FOR ANY
                    INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE
                    DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER
                    INCURRED DIRECTLY OR INDIRECTLY, OR ANY LOSS OF DATA, USE,
                    GOODWILL, OR OTHER INTANGIBLE LOSSES, RESULTING FROM YOUR
                    USE OF THE SERVICE. IN NO EVENT SHALL OUR TOTAL LIABILITY
                    EXCEED THE AMOUNT YOU PAID US IN THE TWELVE (12) MONTHS
                    PRECEDING THE CLAIM.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    12. Indemnification
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    You agree to defend, indemnify, and hold harmless
                    {{ companyName }} and its officers, directors, employees,
                    and agents from any claims, damages, losses, liabilities,
                    and expenses (including reasonable attorneys' fees) arising
                    out of or related to your use of the Service, your User
                    Content, or your violation of these Terms.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    13. Governing Law
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    These Terms shall be governed by and construed in accordance
                    with the laws of the jurisdiction in which
                    {{ companyName }} is incorporated, without regard to its
                    conflict of law provisions. Any disputes arising from these
                    Terms or the Service will be resolved in the courts of that
                    jurisdiction.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    14. Changes to Terms
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We reserve the right to modify or replace these Terms at any
                    time. If a revision is material, we will provide at least 30
                    days' notice prior to any new terms taking effect. What
                    constitutes a material change will be determined at our sole
                    discretion. By continuing to access or use the Service after
                    revisions become effective, you agree to be bound by the
                    revised terms.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    15. Severability
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    If any provision of these Terms is held to be unenforceable
                    or invalid, such provision will be modified to the minimum
                    extent necessary to make it enforceable, and the remaining
                    provisions will continue in full force and effect.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    16. Entire Agreement
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    These Terms, together with our Privacy Policy, constitute
                    the entire agreement between you and {{ companyName }}
                    regarding the Service and supersede all prior agreements and
                    understandings.
                </p>
            </div>

            <div>
                <h2
                    class="text-xl font-semibold text-[var(--text-primary)] mb-4"
                >
                    17. Contact Us
                </h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    If you have any questions about these Terms, please contact
                    us:
                </p>
                <ul
                    class="list-disc list-inside space-y-2 text-[var(--text-secondary)] mt-4"
                >
                    <li>
                        By email:
                        <a
                            :href="`mailto:${supportEmail}`"
                            class="text-[var(--interactive-primary)] hover:underline"
                            >{{ supportEmail }}</a
                        >
                    </li>
                    <li>By visiting the contact page on our website</li>
                </ul>
            </div>

            <hr class="border-(--border-subtle) my-12" />

            <!-- UAT Section -->
            <div
                class="p-6 bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 rounded-xl"
            >
                <h2
                    class="text-xl font-bold text-blue-900 dark:text-blue-100 mb-4 flex items-center gap-2"
                >
                    🧪 User Acceptance Testing (UAT)
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <h4
                            class="font-semibold text-blue-800 dark:text-blue-200 text-sm"
                        >
                            Legal & Compliance
                        </h4>
                        <ul
                            class="text-xs space-y-2 text-blue-700 dark:text-blue-300 list-inside"
                        >
                            <li class="flex items-start gap-2">
                                <div
                                    class="mt-1 h-3 w-3 rounded border border-blue-300 dark:border-blue-700 flex-shrink-0"
                                ></div>
                                <span
                                    >Verify Beta Notice visibility in different
                                    themes</span
                                >
                            </li>
                            <li class="flex items-start gap-2">
                                <div
                                    class="mt-1 h-3 w-3 rounded border border-blue-300 dark:border-blue-700 flex-shrink-0"
                                ></div>
                                <span
                                    >Verify correct company name rendering</span
                                >
                            </li>
                        </ul>
                    </div>
                    <div class="space-y-3">
                        <h4
                            class="font-semibold text-blue-800 dark:text-blue-200 text-sm"
                        >
                            Functional Links
                        </h4>
                        <ul
                            class="text-xs space-y-2 text-blue-700 dark:text-blue-300 list-inside"
                        >
                            <li class="flex items-start gap-2">
                                <div
                                    class="mt-1 h-3 w-3 rounded border border-blue-300 dark:border-blue-700 flex-shrink-0"
                                ></div>
                                <span>Verify support email mailto link</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <div
                                    class="mt-1 h-3 w-3 rounded border border-blue-300 dark:border-blue-700 flex-shrink-0"
                                ></div>
                                <span
                                    >Verify navigation to/from legal pages</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </article>
</template>
