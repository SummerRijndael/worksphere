import { ref } from 'vue';
import { logManager } from '@/utils/LogManager';

export default function useDiagnostics() {
    /**
     * Gathers diagnostic information from the browser.
     * @returns {string} Formatted HTML table of diagnostic info.
     */
    const getDiagnosticsHtml = () => {
        const url = window.location.href;
        const ua = navigator.userAgent;
        const platform = navigator.platform || 'Unknown';
        const screen = `${window.screen.width}x${window.screen.height}`;
        const viewport = `${window.innerWidth}x${window.innerHeight}`;
        const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const lang = navigator.language;

        return `
<br><br>
<hr class="my-4 border-gray-200 dark:border-gray-700">
<h3 class="text-base font-semibold text-[var(--text-primary)] mb-2 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wrench"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
    Diagnostic Information
</h3>
<div class="overflow-x-auto">
    <table class="w-full text-sm text-left border-collapse border border-[var(--border-default)] rounded-md">
        <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)]">
            <tr>
                <th class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium w-1/4">Metric</th>
                <th class="px-3 py-2 border-b border-[var(--border-default)] font-medium">Value</th>
            </tr>
        </thead>
        <tbody class="text-[var(--text-secondary)]">
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">URL</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)] font-mono text-xs break-all">${url}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">User Agent</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)] font-mono text-xs break-all">${ua}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">Platform</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)]">${platform}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">Screen</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)]">${screen}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">Viewport</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)]">${viewport}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium">Timezone</td>
                <td class="px-3 py-2 border-b border-[var(--border-default)]">${tz}</td>
            </tr>
            <tr>
                <td class="px-3 py-2 border-r border-[var(--border-default)] font-medium">Language</td>
                <td class="px-3 py-2 border-[var(--border-default)]">${lang}</td>
            </tr>
        </tbody>
    </table>
</div>

<h3 class="text-base font-semibold text-[var(--text-primary)] mt-6 mb-2 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-history"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
    Recent Navigation
</h3>
<div class="overflow-x-auto">
    <table class="w-full text-sm text-left border-collapse border border-[var(--border-default)] rounded-md">
        <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)]">
            <tr>
                <th class="px-3 py-2 border-b border-r border-[var(--border-default)] font-medium w-1/4">Time</th>
                <th class="px-3 py-2 border-b border-[var(--border-default)] font-medium">URL</th>
            </tr>
        </thead>
        <tbody class="text-[var(--text-secondary)]">
            ${logManager.history.slice(0, 5).map(h => `
                <tr>
                    <td class="px-3 py-2 border-b border-r border-[var(--border-default)] whitespace-nowrap">${h.timestamp}</td>
                    <td class="px-3 py-2 border-b border-[var(--border-default)] font-mono text-xs break-all">${h.url}</td>
                </tr>
            `).join('') || '<tr><td colspan="2" class="px-3 py-2 text-center italic">No history recorded</td></tr>'}
        </tbody>
    </table>
</div>

<h3 class="text-base font-semibold text-[var(--text-primary)] mt-6 mb-2 flex items-center gap-2">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-terminal"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
    Recent Console Logs
</h3>
${logManager.getRecentLogsHtml(30)}
        `.trim();
    };

    return {
        getDiagnosticsHtml
    };
}
