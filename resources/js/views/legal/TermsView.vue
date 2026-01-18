<script setup>
import { appConfig } from '@/config/app';

const lastUpdated = 'December 28, 2025';
const companyName = appConfig.name;
const supportEmail = 'legal@coresync.io';
</script>

<template>
    <article class="prose prose-slate dark:prose-invert max-w-none">
        <header class="not-prose mb-12">
            <h1 class="text-3xl font-bold text-[var(--text-primary)] mb-2">Terms of Service</h1>
            <p class="text-[var(--text-muted)]">Last updated: {{ lastUpdated }}</p>
        </header>

        <section class="space-y-8">
            <!-- Alpha Notice -->
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                <h3 class="text-lg font-semibold text-amber-800 dark:text-amber-200 mb-2 flex items-center gap-2">
                    ⚠️ Alpha Build Notice
                </h3>
                <p class="text-amber-700 dark:text-amber-300 text-sm leading-relaxed">
                    Please be advised that this application is currently in an <strong>Alpha Build Stage</strong>. 
                    Many features are still in a demonstration or active development state. 
                    Functionality may be limited, unstable, or subject to significant changes or removal without prior notice.
                    Data persistence and backward compatibility are not guaranteed during this phase.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">1. Acceptance of Terms</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    By accessing or using {{ companyName }} ("the Service"), you agree to be bound by these Terms of Service ("Terms").
                    You acknowledge the Alpha nature of the Service as described above.
                    If you disagree with any part of the terms, you may not access the Service. These Terms apply to all visitors,
                    users, and others who access or use the Service.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">2. Description of Service</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    {{ companyName }} is a comprehensive project management and team collaboration platform.
                    <strong>The Service is currently in an Alpha phase.</strong> This means that features and capabilities are rapidly evolving.
                    Tools for task management, project tracking, team communication, and analytics are provided for testing and evaluation purposes.
                    The Service is provided "as is" and we expressly reserve the right to modify, suspend, remove, or discontinue any part of the Service, 
                    including specific features or data, at any time without liability.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">3. User Accounts</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    When you create an account with us, you must provide accurate, complete, and current information.
                    You are responsible for:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li>Maintaining the security of your account and password</li>
                    <li>All activities that occur under your account</li>
                    <li>Notifying us immediately of any unauthorized use of your account</li>
                    <li>Ensuring your account information remains accurate and up-to-date</li>
                </ul>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    You must be at least 13 years old to use the Service. By using the Service, you represent that you meet this requirement.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">4. Acceptable Use</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    You agree not to use the Service to:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li>Violate any applicable laws or regulations</li>
                    <li>Infringe upon the rights of others, including intellectual property rights</li>
                    <li>Transmit any harmful, threatening, abusive, or defamatory content</li>
                    <li>Distribute malware, viruses, or other harmful software</li>
                    <li>Attempt to gain unauthorized access to any systems or networks</li>
                    <li>Interfere with or disrupt the integrity or performance of the Service</li>
                    <li>Collect or harvest user data without consent</li>
                    <li>Use the Service for any illegal or unauthorized purpose</li>
                    <li>Impersonate any person or entity or misrepresent your affiliation</li>
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">5. User Content</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    You retain ownership of any content you submit, post, or display on or through the Service ("User Content").
                    By submitting User Content, you grant us a worldwide, non-exclusive, royalty-free license to use, reproduce,
                    modify, and display such content solely for the purpose of operating and providing the Service.
                </p>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    You represent and warrant that you own or have the necessary rights to your User Content and that it does not
                    violate any third-party rights or applicable laws.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">6. Intellectual Property</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    The Service and its original content (excluding User Content), features, and functionality are and will remain
                    the exclusive property of {{ companyName }} and its licensors. The Service is protected by copyright, trademark,
                    and other laws. Our trademarks may not be used in connection with any product or service without prior written consent.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">7. Payment and Billing</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    If you subscribe to a paid plan:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li>You agree to pay all applicable fees as described at the time of purchase</li>
                    <li>Payments are non-refundable except as required by law or as explicitly stated</li>
                    <li>We may change our prices with 30 days' notice</li>
                    <li>You authorize us to charge your payment method on a recurring basis</li>
                    <li>Failure to pay may result in suspension or termination of your account</li>
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">8. Termination</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    We may terminate or suspend your account immediately, without prior notice, for any reason, including:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li>Breach of these Terms</li>
                    <li>Violation of applicable laws</li>
                    <li>Conduct that we determine is harmful to other users or the Service</li>
                    <li>Extended periods of inactivity</li>
                </ul>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    You may terminate your account at any time by contacting us or using the account deletion feature in your settings.
                    Upon termination, your right to use the Service will immediately cease.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">9. Disclaimer of Warranties</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    THE SERVICE IS PROVIDED ON AN "AS IS" AND "AS AVAILABLE" BASIS WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS
                    OR IMPLIED, INCLUDING BUT NOT LIMITED TO IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE,
                    NON-INFRINGEMENT, OR COURSE OF PERFORMANCE. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, SECURE,
                    OR ERROR-FREE, OR THAT DEFECTS WILL BE CORRECTED.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">10. Limitation of Liability</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    TO THE MAXIMUM EXTENT PERMITTED BY LAW, {{ companyName.toUpperCase() }} SHALL NOT BE LIABLE FOR ANY INDIRECT,
                    INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER INCURRED
                    DIRECTLY OR INDIRECTLY, OR ANY LOSS OF DATA, USE, GOODWILL, OR OTHER INTANGIBLE LOSSES, RESULTING FROM YOUR
                    USE OF THE SERVICE. IN NO EVENT SHALL OUR TOTAL LIABILITY EXCEED THE AMOUNT YOU PAID US IN THE TWELVE (12)
                    MONTHS PRECEDING THE CLAIM.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">11. Indemnification</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    You agree to defend, indemnify, and hold harmless {{ companyName }} and its officers, directors, employees,
                    and agents from any claims, damages, losses, liabilities, and expenses (including reasonable attorneys' fees)
                    arising out of or related to your use of the Service, your User Content, or your violation of these Terms.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">12. Governing Law</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    These Terms shall be governed by and construed in accordance with the laws of the jurisdiction in which
                    {{ companyName }} is incorporated, without regard to its conflict of law provisions. Any disputes arising
                    from these Terms or the Service will be resolved in the courts of that jurisdiction.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">13. Changes to Terms</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We reserve the right to modify or replace these Terms at any time. If a revision is material, we will provide
                    at least 30 days' notice prior to any new terms taking effect. What constitutes a material change will be
                    determined at our sole discretion. By continuing to access or use the Service after revisions become effective,
                    you agree to be bound by the revised terms.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">14. Severability</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    If any provision of these Terms is held to be unenforceable or invalid, such provision will be modified to
                    the minimum extent necessary to make it enforceable, and the remaining provisions will continue in full force and effect.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">15. Entire Agreement</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    These Terms, together with our Privacy Policy, constitute the entire agreement between you and {{ companyName }}
                    regarding the Service and supersede all prior agreements and understandings.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">16. Contact Us</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    If you have any questions about these Terms, please contact us:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)] mt-4">
                    <li>By email: <a :href="`mailto:${supportEmail}`" class="text-[var(--interactive-primary)] hover:underline">{{ supportEmail }}</a></li>
                    <li>By visiting the contact page on our website</li>
                </ul>
            </div>
        </section>
    </article>
</template>
