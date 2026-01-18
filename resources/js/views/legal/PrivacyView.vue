<script setup>
import { appConfig } from '@/config/app';

const lastUpdated = 'December 28, 2025';
const companyName = appConfig.name;
const supportEmail = 'privacy@coresync.io';
</script>

<template>
    <article class="prose prose-slate dark:prose-invert max-w-none">
        <header class="not-prose mb-12">
            <h1 class="text-3xl font-bold text-[var(--text-primary)] mb-2">Privacy Policy</h1>
            <p class="text-[var(--text-muted)]">Last updated: {{ lastUpdated }}</p>
        </header>

        <section class="space-y-8">
            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">1. Introduction</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    Welcome to {{ companyName }}. We respect your privacy and are committed to protecting your personal data.
                    This privacy policy explains how we collect, use, disclose, and safeguard your information when you use our service.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">2. Information We Collect</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    We collect information that you provide directly to us, including:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li><strong>Account Information:</strong> Name, email address, password, and profile picture when you create an account.</li>
                    <li><strong>Profile Data:</strong> Additional information you choose to add to your profile, such as job title, company, or phone number.</li>
                    <li><strong>Usage Data:</strong> Information about how you interact with our services, including pages visited, features used, and actions taken.</li>
                    <li><strong>Device Information:</strong> Browser type, operating system, IP address, and device identifiers.</li>
                    <li><strong>Communication Data:</strong> Messages, feedback, and support requests you send to us.</li>
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">3. How We Use Your Information</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    We use the information we collect to:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li>Provide, maintain, and improve our services</li>
                    <li>Process transactions and send related information</li>
                    <li>Send you technical notices, updates, security alerts, and support messages</li>
                    <li>Respond to your comments, questions, and customer service requests</li>
                    <li>Communicate with you about products, services, and events</li>
                    <li>Monitor and analyze trends, usage, and activities</li>
                    <li>Detect, investigate, and prevent fraudulent transactions and other illegal activities</li>
                    <li>Personalize and improve your experience</li>
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">4. Information Sharing</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    We do not sell, trade, or rent your personal information to third parties. We may share your information in the following situations:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li><strong>Service Providers:</strong> With vendors and service providers who need access to perform services on our behalf.</li>
                    <li><strong>Legal Requirements:</strong> If required by law or in response to valid legal requests.</li>
                    <li><strong>Business Transfers:</strong> In connection with any merger, sale, or transfer of company assets.</li>
                    <li><strong>With Your Consent:</strong> When you have given us explicit consent to share your information.</li>
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">5. Data Security</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We implement appropriate technical and organizational security measures to protect your personal data against
                    unauthorized access, alteration, disclosure, or destruction. These measures include encryption, secure servers,
                    and regular security assessments. However, no method of transmission over the Internet or electronic storage
                    is 100% secure, and we cannot guarantee absolute security.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">6. Data Retention</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We retain your personal data only for as long as necessary to fulfill the purposes for which it was collected,
                    including to satisfy legal, accounting, or reporting requirements. When we no longer need your personal data,
                    we will securely delete or anonymize it.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">7. Your Rights</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed mb-4">
                    Depending on your location, you may have the following rights regarding your personal data:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)]">
                    <li><strong>Access:</strong> Request access to your personal data</li>
                    <li><strong>Correction:</strong> Request correction of inaccurate or incomplete data</li>
                    <li><strong>Deletion:</strong> Request deletion of your personal data</li>
                    <li><strong>Portability:</strong> Request a copy of your data in a structured, machine-readable format</li>
                    <li><strong>Objection:</strong> Object to processing of your personal data</li>
                    <li><strong>Restriction:</strong> Request restriction of processing</li>
                </ul>
                <p class="text-[var(--text-secondary)] leading-relaxed mt-4">
                    To exercise any of these rights, please contact us at {{ supportEmail }}.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">8. Cookies and Tracking</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We use cookies and similar tracking technologies to track activity on our service and store certain information.
                    Cookies are files with a small amount of data that may include an anonymous unique identifier. You can instruct
                    your browser to refuse all cookies or to indicate when a cookie is being sent. However, if you do not accept
                    cookies, you may not be able to use some portions of our service.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">9. Third-Party Services</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    Our service may contain links to third-party websites or services that are not operated by us. We have no control
                    over, and assume no responsibility for, the content, privacy policies, or practices of any third-party sites or
                    services. We encourage you to review the privacy policies of any third-party sites you visit.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">10. Children's Privacy</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    Our service is not intended for children under 13 years of age. We do not knowingly collect personal information
                    from children under 13. If we become aware that we have collected personal data from a child under 13, we will
                    take steps to delete that information.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">11. Changes to This Policy</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    We may update this privacy policy from time to time. We will notify you of any changes by posting the new policy
                    on this page and updating the "Last updated" date. You are advised to review this policy periodically for any changes.
                </p>
            </div>

            <div>
                <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4">12. Contact Us</h2>
                <p class="text-[var(--text-secondary)] leading-relaxed">
                    If you have any questions about this Privacy Policy, please contact us:
                </p>
                <ul class="list-disc list-inside space-y-2 text-[var(--text-secondary)] mt-4">
                    <li>By email: <a :href="`mailto:${supportEmail}`" class="text-[var(--interactive-primary)] hover:underline">{{ supportEmail }}</a></li>
                    <li>By visiting the contact page on our website</li>
                </ul>
            </div>
        </section>
    </article>
</template>
