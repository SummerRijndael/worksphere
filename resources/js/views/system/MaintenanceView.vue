<script setup>
import { ref, onMounted, onUnmounted } from "vue";
import { useRouter } from "vue-router";
import {
    Button,
    Badge,
    Input,
    Modal,
    Checkbox,
    Textarea,
    SelectFilter,
} from "@/components/ui";
import {
    Wrench,
    Database,
    Trash2,
    RefreshCw,
    HardDrive,
    Clock,
    AlertTriangle,
    CheckCircle,
    Play,
    Loader2,
    Server,
    XCircle,
    Activity,
    Archive,
    Download,
    FileText,
    LayoutGrid,
    List as ListIcon,
    Lock,
    Globe,
    Cloud,
    MessageSquare,
    Shield,
    ChevronLeft,
    ChevronRight,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import axios from "axios";
import { isEchoAvailable } from "../../echo";

import { Line } from "vue-chartjs";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from "chart.js";

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const router = useRouter();
const isLoading = ref(true);
const isClearing = ref({
    cache: false,
    views: false,
    sessions: false,
    logs: false,
});

const maintenanceMode = ref(false);
const maintenanceInfo = ref(null);
const isTogglingMaintenance = ref(false);

const systemInfo = ref({
    php_version: null,
    laravel_version: null,
    db_engine: null,
    db_version: null,
    database_size: null,
    cache_size: null,
    logs_size: null,
    logs_count: null,
    server_time: null,
    uptime: null,
    os_name: null,
    os_version: null,
    server_software: null,
    disk_total: null,
    disk_used: null,
    disk_free: null,
    memory_total: null,
    memory_used: null,
    cache_driver: null,
    cache_status: null,
    cache_keys: null,
    cache_memory_used: null,
    cache_memory_peak: null,
    cache_memory_limit: null,
    cache_hits: null,
    cache_misses: null,
    cpu_model: null,
    disk_model: null,
});

const scheduledTasks = ref([]);
const isLoadingTasks = ref(false);
const runningTask = ref(null);

// Queue Management
const queueStats = ref(null);
const pendingJobs = ref([]);
const failedJobs = ref([]);
const completedJobs = ref([]);
const isLoadingQueue = ref(false);
const queueActionRunning = ref(null);
const queuePage = ref(1);
const queuePerPage = ref(10);
const totalFailedJobs = ref(0);
const totalPendingJobs = ref(0);
const activeQueueTab = ref("pending"); // 'pending' | 'failed' | 'completed'

// External Services
const externalServices = ref(null);
const isLoadingExternalServices = ref(false);

// Storage Stats
const storageStats = ref(null);
const isLoadingStorage = ref(false);

// Backup Management
const backups = ref([]);
const isLoadingBackups = ref(false);
const isCreatingBackup = ref(false);
const creatingBackupOption = ref(null);
const backupActionRunning = ref(null);
const backupsViewMode = ref("list");
const selectedBackups = ref([]);
const backupPagination = ref({
    current_page: 1,
    per_page: 20,
    total: 0,
    last_page: 1,
});
const perPageOptions = [20, 50, 100, 200];
const showSecureDownloadModal = ref(false);
const secureDownloadForm = ref({ password: "", reason: "" });
const isSecureDownloading = ref(false);
const isBulkDeleting = ref(false);

// Maintenance Mode Modal
const showMaintenanceModal = ref(false);
const maintenanceForm = ref({
    password: "",
    reason: "",
    secret: "",
});

// New System Stats
const phpConfiguration = ref(null);
const isLoadingPhp = ref(false); // Fix: Define specific loading state
const databaseHealth = ref([]);
const isLoadingDatabase = ref(false); // Fix: Define specific loading state
const databasePagination = ref({
    current_page: 1,
    per_page: 10,
    total: 0,
    last_page: 1,
});
const logData = ref(null);
const isLoadingLogs = ref(false);
const showLogsModal = ref(false);
const maintenanceFormErrors = ref({});
const isEnablingMaintenance = ref(false);

// Sessions Clear Modal
const showSessionsModal = ref(false);
const sessionsPassword = ref("");
const sessionsPasswordError = ref("");
const isClearingSessions = ref(false);

// Fetch system info from API
const fetchSystemInfo = async () => {
    try {
        const response = await axios.get("/api/maintenance/system-info");
        systemInfo.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch system info:", error);
        toast.error("Failed to load system information");
    }
};

// Fetch maintenance status from API
const fetchMaintenanceStatus = async () => {
    try {
        const response = await axios.get("/api/maintenance/status");
        maintenanceMode.value = response.data.data.enabled;
        maintenanceInfo.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch maintenance status:", error);
    }
};

// Fetch scheduled tasks from API
const fetchScheduledTasks = async () => {
    isLoadingTasks.value = true;
    try {
        const response = await axios.get("/api/maintenance/scheduled-tasks");
        scheduledTasks.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch scheduled tasks:", error);
        toast.error("Failed to load scheduled tasks");
    } finally {
        isLoadingTasks.value = false;
    }
};

// Fetch Queue Stats & Failed Jobs
const fetchQueueData = async () => {
    isLoadingQueue.value = true;
    try {
        const [statsRes, pendingRes, failedRes, completedRes] =
            await Promise.all([
                axios.get("/api/maintenance/queue/stats"),
                axios.get(
                    `/api/maintenance/queue/pending?page=${queuePage.value}&per_page=${queuePerPage.value}`
                ),
                axios.get(
                    `/api/maintenance/queue/failed?page=${queuePage.value}&per_page=${queuePerPage.value}`
                ),
                axios.get("/api/maintenance/queue/completed?limit=50"),
            ]);
        queueStats.value = statsRes.data.data;
        pendingJobs.value = pendingRes.data.data?.data || [];
        totalPendingJobs.value = pendingRes.data.data?.total || 0;
        failedJobs.value = failedRes.data.data.data;
        totalFailedJobs.value = failedRes.data.data.total;
        completedJobs.value = completedRes.data.data;
    } catch (error) {
        console.error("Failed to fetch queue data:", error);
        toast.error("Failed to load queue information");
    } finally {
        isLoadingQueue.value = false;
    }
};

// Fetch Storage Statisics
const fetchStorageStats = async () => {
    isLoadingStorage.value = true;
    try {
        const [localRes, s3Res] = await Promise.all([
            axios.get("/api/maintenance/storage?type=local"),
            axios.get("/api/maintenance/storage?type=s3"),
        ]);

        storageStats.value = {
            local: localRes.data.data.local,
            s3: s3Res.data.data.s3,
        };
    } catch (error) {
        console.error("Failed to fetch storage stats:", error);
        toast.error("Failed to load storage statistics");
    } finally {
        isLoadingStorage.value = false;
    }
};

const fetchAdditionalSystemInfo = async () => {
    isLoadingPhp.value = true;
    try {
        const phpRes = await axios.get("/api/maintenance/php-info");
        phpConfiguration.value = phpRes.data.data;
    } catch (error) {
        console.error("Failed to fetch additional info:", error);
    } finally {
        isLoadingPhp.value = false;
    }
};

const fetchExternalServices = async () => {
    isLoadingExternalServices.value = true;
    try {
        const response = await axios.get("/api/maintenance/external-services");
        externalServices.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch external services:", error);
    } finally {
        isLoadingExternalServices.value = false;
    }
};

const fetchDatabaseHealth = async (page = 1) => {
    isLoadingDatabase.value = true;
    databasePagination.value.current_page = page;
    try {
        const response = await axios.get(
            `/api/maintenance/database-health?page=${page}&per_page=${databasePagination.value.per_page}`
        );
        databaseHealth.value = response.data.data.data;
        const meta = response.data.data.pagination;
        databasePagination.value = {
            current_page: meta.current_page,
            per_page: meta.per_page,
            total: meta.total,
            last_page: meta.last_page,
        };
    } catch (error) {
        console.error("Failed to fetch DB health:", error);
    } finally {
        isLoadingDatabase.value = false;
    }
};

const fetchBackups = async (page = 1) => {
    isLoadingBackups.value = true;
    backupPagination.value.current_page = page;
    try {
        const response = await axios.get(
            `/api/maintenance/backups?page=${page}&per_page=${backupPagination.value.per_page}`
        );
        backups.value = response.data.data.data;
        const meta = response.data.data.pagination;
        backupPagination.value = {
            current_page: meta.current_page,
            per_page: meta.per_page,
            total: meta.total,
            last_page: meta.last_page,
        };
    } catch (error) {
        console.error("Failed to fetch backups:", error);
    } finally {
        isLoadingBackups.value = false;
    }
};

const createBackup = async (option = "both") => {
    isCreatingBackup.value = true;
    creatingBackupOption.value = option;
    try {
        await axios.post("/api/maintenance/backups/create", { option });
        toast.success("Backup created successfully");
        await fetchBackups(1);
    } catch (error) {
        toast.error("Failed to create backup");
    } finally {
        isCreatingBackup.value = false;
        creatingBackupOption.value = null;
    }
};

const deleteBackup = async (path) => {
    if (!confirm("Are you sure you want to delete this backup?")) return;

    backupActionRunning.value = path;
    try {
        await axios.post("/api/maintenance/backups/delete", { path });
        toast.success("Backup deleted");
        await fetchBackups(backupPagination.value.current_page);
    } catch (error) {
        toast.error("Failed to delete backup");
    } finally {
        backupActionRunning.value = null;
    }
};

const toggleBackupSelection = (path) => {
    if (selectedBackups.value.includes(path)) {
        selectedBackups.value = selectedBackups.value.filter((p) => p !== path);
    } else {
        selectedBackups.value.push(path);
    }
};

const selectAllBackups = () => {
    if (selectedBackups.value.length === backups.value.length) {
        selectedBackups.value = [];
    } else {
        selectedBackups.value = backups.value.map((b) => b.path);
    }
};

const handleBulkDelete = async () => {
    if (
        !confirm(
            `Are you sure you want to delete ${selectedBackups.value.length} backups?`
        )
    )
        return;
    isBulkDeleting.value = true;
    try {
        await axios.post("/api/maintenance/backups/bulk-delete", {
            paths: selectedBackups.value,
        });
        toast.success("Backups deleted successfully");
        selectedBackups.value = [];
        await fetchBackups(backupPagination.value.current_page);
    } catch (error) {
        toast.error("Failed to delete backups");
    } finally {
        isBulkDeleting.value = false;
    }
};

const openSecureDownloadModal = () => {
    if (selectedBackups.value.length === 0) return;
    secureDownloadForm.value = { password: "", reason: "" };
    showSecureDownloadModal.value = true;
};

const downloadBackup = (path) => {
    selectedBackups.value = [path];
    openSecureDownloadModal();
};

const handleSecureDownload = async () => {
    isSecureDownloading.value = true;
    if (
        !secureDownloadForm.value.password ||
        !secureDownloadForm.value.reason
    ) {
        toast.error("Please fill all fields");
        isSecureDownloading.value = false;
        return;
    }

    try {
        const response = await axios.post(
            "/api/maintenance/backups/secure-download",
            {
                password: secureDownloadForm.value.password,
                reason: secureDownloadForm.value.reason,
                paths: selectedBackups.value,
            },
            { responseType: "blob" }
        );

        // Handle file download
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        const contentDisposition = response.headers["content-disposition"];
        let filename = "secure-backup.zip";
        if (contentDisposition) {
            const fileNameMatch =
                contentDisposition.match(/filename="?([^"]+)"?/);
            if (fileNameMatch && fileNameMatch.length === 2)
                filename = fileNameMatch[1];
        }

        link.setAttribute("download", filename);
        document.body.appendChild(link);
        link.click();
        link.remove();

        toast.success("Secure backup downloaded. Password sent to your email.");
        showSecureDownloadModal.value = false;
        selectedBackups.value = [];
    } catch (error) {
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const errorData = JSON.parse(reader.result);
                toast.error(errorData.message || "Download failed");
            } catch (e) {
                toast.error("Download failed");
            }
        };
        if (error.response && error.response.data instanceof Blob) {
            reader.readAsText(error.response.data);
        } else {
            toast.error("Download failed");
        }
    } finally {
        isSecureDownloading.value = false;
    }
};

const fetchLogs = async () => {
    isLoadingLogs.value = true;
    try {
        const response = await axios.get("/api/maintenance/logs?lines=100");
        logData.value = response.data.data;
        showLogsModal.value = true;
    } catch (error) {
        toast.error("Failed to load logs");
    } finally {
        isLoadingLogs.value = false;
    }
};

// Queue Actions
const retryJob = async (id) => {
    queueActionRunning.value = `retry-${id}`;
    try {
        await axios.post(`/api/maintenance/queue/retry/${id}`);
        toast.success("Job retry initiated");
        await fetchQueueData();
    } catch (error) {
        toast.error("Failed to retry job");
    } finally {
        queueActionRunning.value = null;
    }
};

const forgetJob = async (id) => {
    queueActionRunning.value = `forget-${id}`;
    try {
        await axios.post(`/api/maintenance/queue/forget/${id}`);
        toast.success("Job removed");
        await fetchQueueData();
    } catch (error) {
        toast.error("Failed to remove job");
    } finally {
        queueActionRunning.value = null;
    }
};

const flushFailedJobs = async () => {
    if (
        !confirm(
            "Are you sure you want to flush all failed jobs? This cannot be undone."
        )
    )
        return;

    queueActionRunning.value = "flush";
    try {
        await axios.post("/api/maintenance/queue/flush");
        toast.success("All failed jobs flushed");
        await fetchQueueData();
    } catch (error) {
        toast.error("Failed to flush jobs");
    } finally {
        queueActionRunning.value = null;
    }
};

const retryAllJobs = async () => {
    if (!confirm("Are you sure you want to retry all failed jobs?")) return;

    queueActionRunning.value = "retry-all";
    try {
        await axios.post("/api/maintenance/queue/retry/all");
        toast.success("All failed jobs retry initiated");
        await fetchQueueData();
    } catch (error) {
        toast.error("Failed to retry jobs");
    } finally {
        queueActionRunning.value = null;
    }
};

// Load all data on mount
onMounted(async () => {
    try {
        await Promise.all([
            fetchSystemInfo(),
            fetchMaintenanceStatus(),
            fetchScheduledTasks(),
            fetchQueueData(),
            fetchStorageStats(),
            fetchAdditionalSystemInfo(),
            fetchDatabaseHealth(),
            fetchBackups(),
            fetchExternalServices(),
        ]);

        setupRealtimeMetrics();
        setupRealtimeScheduledTasks();
        setupRealtimeQueueStats();
        setupRealtimeCacheStats();
    } finally {
        isLoading.value = false;
    }
});

// Refresh system info
const refreshSystemInfo = async () => {
    isLoading.value = true;
    await Promise.all([
        fetchSystemInfo(),
        fetchAdditionalSystemInfo(),
        fetchDatabaseHealth(databasePagination.value.current_page),
        fetchBackups(backupPagination.value.current_page),
        fetchExternalServices(),
    ]);
    isLoading.value = false;
    toast.success("System information refreshed");
};

// Open maintenance modal
const openMaintenanceModal = () => {
    maintenanceForm.value = { password: "", reason: "", secret: "" };
    maintenanceFormErrors.value = {};
    showMaintenanceModal.value = true;
};

// Enable maintenance mode
const enableMaintenance = async () => {
    maintenanceFormErrors.value = {};
    isEnablingMaintenance.value = true;

    try {
        const response = await axios.post(
            "/api/maintenance/enable",
            maintenanceForm.value
        );
        maintenanceMode.value = true;
        maintenanceInfo.value = response.data.data;
        showMaintenanceModal.value = false;
        toast.success("Maintenance mode enabled");
    } catch (error) {
        if (error.response?.status === 422) {
            maintenanceFormErrors.value = error.response.data.errors || {};
            if (error.response.data.message) {
                toast.error(error.response.data.message);
            }
        } else {
            toast.error("Failed to enable maintenance mode");
        }
    } finally {
        isEnablingMaintenance.value = false;
    }
};

// Disable maintenance mode
const disableMaintenance = async () => {
    isTogglingMaintenance.value = true;
    try {
        await axios.post("/api/maintenance/disable");
        maintenanceMode.value = false;
        maintenanceInfo.value = null;
        toast.success("Maintenance mode disabled");
    } catch (error) {
        toast.error("Failed to disable maintenance mode");
    } finally {
        isTogglingMaintenance.value = false;
    }
};

// Clear cache
const clearCache = async (type) => {
    // Sessions require password confirmation
    if (type === "sessions") {
        sessionsPassword.value = "";
        sessionsPasswordError.value = "";
        showSessionsModal.value = true;
        return;
    }

    isClearing.value[type] = true;
    try {
        let endpoint = "";
        switch (type) {
            case "cache":
                endpoint = "/api/maintenance/cache/clear";
                break;
            case "views":
                endpoint = "/api/maintenance/views/clear";
                break;
            case "logs":
                endpoint = "/api/maintenance/logs/clear";
                break;
        }

        const response = await axios.post(endpoint);
        toast.success(
            response.data.message ||
                `${
                    type.charAt(0).toUpperCase() + type.slice(1)
                } cleared successfully`
        );

        // Refresh system info to update sizes
        await fetchSystemInfo();
    } catch (error) {
        toast.error(`Failed to clear ${type}`);
    } finally {
        isClearing.value[type] = false;
    }
};

// Clear sessions with password
const clearSessionsWithPassword = async () => {
    sessionsPasswordError.value = "";
    isClearingSessions.value = true;

    try {
        const response = await axios.post("/api/maintenance/sessions/clear", {
            password: sessionsPassword.value,
        });
        showSessionsModal.value = false;
        toast.success(
            response.data.message || "All sessions cleared successfully"
        );
    } catch (error) {
        if (error.response?.status === 422) {
            sessionsPasswordError.value =
                error.response.data.errors?.password?.[0] || "Invalid password";
        } else {
            toast.error("Failed to clear sessions");
        }
    } finally {
        isClearingSessions.value = false;
    }
};

// Run scheduled task
const runTask = async (taskName) => {
    runningTask.value = taskName;
    try {
        const response = await axios.post(
            `/api/maintenance/scheduled-tasks/${taskName}/run`
        );
        if (response.data.success) {
            toast.success(response.data.message);
            await fetchScheduledTasks();
        } else {
            toast.error(response.data.message);
        }
    } catch (error) {
        toast.error("Failed to run task");
    } finally {
        runningTask.value = null;
    }
};

// Format last run time
const formatLastRun = (isoString) => {
    if (!isoString) return "Never";
    const date = new Date(isoString);
    const now = new Date();
    const diff = now - date;

    if (diff < 60000) return "Just now";
    if (diff < 3600000) return `${Math.floor(diff / 60000)} min ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)} hour(s) ago`;
    return date.toLocaleDateString();
};

// System Metrics Charts
const maxDataPoints = 30;
const chartData = ref({
    labels: Array(maxDataPoints).fill(""),
    datasets: [
        {
            label: "CPU Usage %",
            backgroundColor: "rgba(59, 130, 246, 0.2)",
            borderColor: "rgb(59, 130, 246)",
            data: Array(maxDataPoints).fill(0),
            fill: true,
            tension: 0.4,
        },
        {
            label: "Memory Usage %",
            backgroundColor: "rgba(168, 85, 247, 0.2)",
            borderColor: "rgb(168, 85, 247)",
            data: Array(maxDataPoints).fill(0),
            fill: true,
            tension: 0.4,
        },
    ],
});

// View Mode State
const cpuViewMode = ref("total"); // 'total' | 'cores'

const toggleCpuView = () => {
    cpuViewMode.value = cpuViewMode.value === "total" ? "cores" : "total";
    // Reset chart data structure when switching modes
    const maxPoints = 30;

    if (cpuViewMode.value === "total") {
        chartData.value.datasets = [
            {
                label: "CPU Usage %",
                backgroundColor: "rgba(59, 130, 246, 0.2)",
                borderColor: "rgb(59, 130, 246)",
                data: Array(maxPoints).fill(0),
                fill: true,
                tension: 0.4,
            },
            {
                label: "Memory Usage %",
                backgroundColor: "rgba(168, 85, 247, 0.2)",
                borderColor: "rgb(168, 85, 247)",
                data: Array(maxPoints).fill(0),
                fill: true,
                tension: 0.4,
            },
        ];
    } else {
        // Cores mode - Memory stays as dataset 0 (or we move it to separate chart logic if needed)
        // Actually, to keep it simple, we will have:
        // Dataset 0...N: CPU Cores
        // Last Dataset: Memory
        // Wait, current logic updates datasets[0] and datasets[1].
        // Let's refactor: Dataset 0 is always CPU (multi-line or single), Dataset 1 is Memory.
        // Issue: Chart.js lines interact.
        // Better: When in 'cores' mode, replace dataset[0] with N datasets, and keep memory at the end?
        // Or simply hide/show datasets.
        // Let's dynamically rebuild datasets on every update for simplicity or rely on initial setup.
        // Simpler approach:
        // Total Mode: datasets[0] = CPU Total, datasets[1] = Memory
        // Cores Mode: datasets[0...N-1] = CPU Cores, datasets[N] = Memory
    }
};

// Colors for cores
const getCoreColor = (index, total) => {
    const hue = (index * 360) / total;
    return `hsl(${hue}, 70%, 50%)`;
};

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 0 },
    scales: {
        y: {
            beginAtZero: true,
            max: 100,
            grid: { color: "rgba(255, 255, 255, 0.1)" },
            ticks: { color: "rgba(156, 163, 175, 1)" },
        },
        x: {
            display: false,
        },
    },
    plugins: {
        legend: {
            labels: { color: "rgba(156, 163, 175, 1)" },
        },
    },
};

const currentMetrics = ref({
    cpu: 0,
    memory: { used: "0 B", total: "0 B", percent: 0 },
});

const setupRealtimeMetrics = () => {
    if (!isEchoAvailable() || !window.Echo) {
        console.debug("Echo not available, skipping real-time metrics");
        return;
    }

    const echoInstance = window.Echo;

    echoInstance
        .channel("system-metrics")
        .listen("SystemMetricsUpdated", (e) => {
            const metrics = e.metrics;

            // Update current display values
            currentMetrics.value = {
                cpu: metrics.cpu_load,
                memory: metrics.memory,
            };

            const numCores = metrics.cpu_cores ? metrics.cpu_cores.length : 0;
            const newLabels = [
                ...chartData.value.labels.slice(1),
                new Date().toLocaleTimeString(),
            ];

            // Re-construct datasets if structure mismatches (e.g. initial load or mode switch)
            let newDatasets = [];

            // Safe access to memory data from previous state or init new
            const memDataset = chartData.value.datasets.find(
                (d) => d.label === "Memory Usage %"
            );
            const memoryData = [
                ...(memDataset
                    ? memDataset.data.slice(1)
                    : Array(maxDataPoints - 1).fill(0)),
                metrics.memory.percent,
            ];

            if (cpuViewMode.value === "total") {
                const cpuDataset = chartData.value.datasets.find(
                    (d) => d.label === "CPU Usage %"
                );
                const cpuTotalData = [
                    ...(cpuDataset
                        ? cpuDataset.data.slice(1)
                        : Array(maxDataPoints - 1).fill(0)),
                    metrics.cpu_load,
                ];

                newDatasets = [
                    {
                        label: "CPU Usage %",
                        backgroundColor: "rgba(59, 130, 246, 0.2)",
                        borderColor: "rgb(59, 130, 246)",
                        data: cpuTotalData,
                        fill: true,
                        tension: 0.4,
                    },
                    {
                        label: "Memory Usage %",
                        backgroundColor: "rgba(168, 85, 247, 0.2)",
                        borderColor: "rgb(168, 85, 247)",
                        data: memoryData,
                        fill: true,
                        tension: 0.4,
                    },
                ];
            } else {
                // Cores Mode
                for (let i = 0; i < numCores; i++) {
                    const coreLabel = `Core ${i}`;
                    const prevDataset = chartData.value.datasets.find(
                        (d) => d.label === coreLabel
                    );
                    const prevData = prevDataset
                        ? prevDataset.data.slice(1)
                        : Array(maxDataPoints - 1).fill(0);

                    newDatasets.push({
                        label: coreLabel,
                        borderColor: getCoreColor(i, numCores),
                        backgroundColor: "transparent",
                        data: [...prevData, metrics.cpu_cores[i]],
                        fill: false,
                        tension: 0.4,
                        borderWidth: 1,
                        pointRadius: 0,
                    });
                }

                // Add Memory at the end
                newDatasets.push({
                    label: "Memory Usage %",
                    backgroundColor: "rgba(168, 85, 247, 0.2)",
                    borderColor: "rgb(168, 85, 247)",
                    data: memoryData,
                    fill: true,
                    tension: 0.4,
                });
            }

            chartData.value = {
                labels: newLabels,
                datasets: newDatasets,
            };
        });
};

// Setup real-time scheduled task updates
const setupRealtimeScheduledTasks = () => {
    if (!isEchoAvailable() || !window.Echo) {
        console.debug("Echo not available, skipping real-time scheduled tasks");
        return;
    }

    const echoInstance = window.Echo;

    echoInstance
        .channel("scheduled-tasks")
        .listen("ScheduledTaskStatusChanged", (e) => {
            const taskUpdate = e.task;

            // Find and update the matching task in the list
            const taskIndex = scheduledTasks.value.findIndex(
                (t) => t.name === taskUpdate.name
            );
            if (taskIndex !== -1) {
                scheduledTasks.value[taskIndex] = {
                    ...scheduledTasks.value[taskIndex],
                    status: taskUpdate.status,
                    start_time: taskUpdate.start_time,
                    last_run: taskUpdate.last_run,
                    duration: taskUpdate.duration,
                };
            }
        });
};

// Setup real-time queue stats updates
const setupRealtimeQueueStats = () => {
    if (!isEchoAvailable() || !window.Echo) {
        console.debug("Echo not available, skipping real-time queue stats");
        return;
    }

    const echoInstance = window.Echo;

    echoInstance.channel("queue-stats").listen("QueueStatsUpdated", (e) => {
        const stats = e.stats;
        queueStats.value = {
            ...queueStats.value,
            ...stats,
        };

        // Refresh failed jobs list when stats change
        fetchQueueData();
    });
};

// Setup real-time cache stats updates
const setupRealtimeCacheStats = () => {
    if (!isEchoAvailable() || !window.Echo) {
        console.debug("Echo not available, skipping real-time cache stats");
        return;
    }

    const echoInstance = window.Echo;

    echoInstance.channel("cache-stats").listen("CacheStatsUpdated", (e) => {
        const stats = e.stats;
        systemInfo.value = {
            ...systemInfo.value,
            ...stats,
        };
    });
};

onUnmounted(() => {
    if (isEchoAvailable() && window.Echo) {
        window.Echo.leave("system-metrics");
        window.Echo.leave("scheduled-tasks");
        window.Echo.leave("queue-stats");
        window.Echo.leave("cache-stats");
    }
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Maintenance
                </h1>
                <p class="text-[var(--text-secondary)]">
                    System maintenance and optimization tools.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Badge
                    :variant="
                        maintenanceMode
                            ? 'danger'
                            : systemInfo.health?.status === 'healthy'
                            ? 'outline'
                            : 'warning'
                    "
                    class="h-8 px-3"
                >
                    <span
                        class="w-2 h-2 rounded-full mr-2"
                        :class="
                            maintenanceMode
                                ? 'bg-red-500 animate-pulse'
                                : systemInfo.health?.status === 'healthy'
                                ? 'bg-green-500'
                                : 'bg-yellow-500'
                        "
                    ></span>
                    {{
                        maintenanceMode
                            ? "Maintenance Mode"
                            : systemInfo.health?.status === "healthy"
                            ? "System Online"
                            : "System " +
                              (systemInfo.health?.status
                                  ? systemInfo.health.status
                                        .charAt(0)
                                        .toUpperCase() +
                                    systemInfo.health.status.slice(1)
                                  : "Degraded")
                    }}
                </Badge>
                <Button
                    v-if="maintenanceMode"
                    variant="outline"
                    size="sm"
                    @click="disableMaintenance"
                    :loading="isTogglingMaintenance"
                >
                    Disable Maintenance
                </Button>
                <Button
                    v-else
                    variant="danger"
                    size="sm"
                    @click="openMaintenanceModal"
                >
                    Enable Maintenance
                </Button>
                <div class="h-6 w-px bg-[var(--border-default)] mx-2"></div>

                <a href="/horizon" target="_blank">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Open Horizon"
                        class="text-purple-500 hover:text-purple-600 hover:bg-purple-50"
                    >
                        <Activity class="w-4 h-4" />
                    </Button>
                </a>

                <a href="/pulse" target="_blank">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Open Pulse"
                        class="text-red-500 hover:text-red-600 hover:bg-red-50"
                    >
                        <Activity class="w-4 h-4" />
                    </Button>
                </a>

                <Button
                    variant="ghost"
                    size="icon"
                    @click="refreshSystemInfo"
                    :disabled="isLoading"
                >
                    <RefreshCw
                        class="w-4 h-4"
                        :class="{ 'animate-spin': isLoading }"
                    />
                </Button>
            </div>
        </div>

        <!-- System Info -->
        <div
            v-if="isLoading"
            class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4"
        >
            <div
                v-for="i in 7"
                :key="i"
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 animate-pulse"
            >
                <div
                    class="h-3 bg-[var(--surface-secondary)] rounded w-20 mb-2"
                ></div>
                <div
                    class="h-6 bg-[var(--surface-secondary)] rounded w-16"
                ></div>
            </div>
        </div>
        <div
            v-else
            class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4"
        >
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">PHP Version</p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.php_version || "N/A" }}
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">
                    Laravel Version
                </p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.laravel_version || "N/A" }}
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">Database</p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.db_engine || "N/A" }}
                    <span
                        v-if="systemInfo.db_version"
                        class="text-sm text-[var(--text-secondary)]"
                        >{{ systemInfo.db_version }}</span
                    >
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">
                    Database Size
                </p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.database_size || "N/A" }}
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">Cache Size</p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.cache_size || "N/A" }}
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">Logs Size</p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.logs_size || "N/A" }}
                </p>
            </div>
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4"
            >
                <p class="text-xs text-[var(--text-muted)] mb-1">Uptime</p>
                <p class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ systemInfo.uptime || "N/A" }}
                </p>
            </div>
        </div>

        <!-- System Resources (Moved to Server Information) -->

        <!-- Maintenance Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden md:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-green-500/10 flex items-center justify-center"
                    >
                        <Server class="w-4 h-4 text-green-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Server Information
                    </h3>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Operating System -->
                        <div class="space-y-3">
                            <h4
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide"
                            >
                                Operating System
                            </h4>
                            <div class="space-y-2">
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Name</span
                                    >
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >{{ systemInfo.os_name || "N/A" }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Version</span
                                    >
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >{{
                                            systemInfo.os_version || "N/A"
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Server Software</span
                                    >
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >{{
                                            systemInfo.server_software || "N/A"
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Server Time</span
                                    >
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >{{
                                            systemInfo.server_time || "N/A"
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Hardware Specs -->
                        <div class="space-y-3">
                            <h4
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide"
                            >
                                Hardware Specs
                            </h4>
                            <div class="space-y-2">
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-xs text-[var(--text-secondary)]"
                                            >CPU Model</span
                                        >
                                        <span
                                            class="text-sm font-medium text-[var(--text-primary)] break-words"
                                        >
                                            {{
                                                systemInfo.cpu_model ||
                                                "Restricted Hardware Specs"
                                            }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-xs text-[var(--text-secondary)]"
                                            >Memory Total</span
                                        >
                                        <span
                                            class="text-sm font-medium text-[var(--text-primary)]"
                                        >
                                            {{
                                                systemInfo.memory_total ||
                                                "Restricted Hardware Specs"
                                            }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div class="flex flex-col gap-1">
                                        <span
                                            class="text-xs text-[var(--text-secondary)]"
                                            >Disk Model</span
                                        >
                                        <span
                                            class="text-sm font-medium text-[var(--text-primary)]"
                                        >
                                            {{
                                                systemInfo.disk_model ||
                                                "Restricted Hardware Specs"
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Storage & Memory -->
                        <div class="space-y-3">
                            <h4
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide"
                            >
                                Resources
                            </h4>
                            <div class="space-y-2">
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div
                                        class="flex justify-between items-center mb-1"
                                    >
                                        <span
                                            class="text-sm text-[var(--text-secondary)]"
                                            >Disk Space</span
                                        >
                                        <span
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{ systemInfo.disk_used || "N/A" }}
                                            /
                                            {{ systemInfo.disk_total || "N/A" }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="
                                            systemInfo.disk_total &&
                                            systemInfo.disk_used
                                        "
                                        class="w-full bg-[var(--surface-primary)] rounded-full h-2 overflow-hidden"
                                    >
                                        <div
                                            class="h-full bg-gradient-to-r from-blue-500 to-blue-600 transition-all duration-300"
                                            :style="{
                                                width: `${
                                                    (parseFloat(
                                                        systemInfo.disk_used
                                                    ) /
                                                        parseFloat(
                                                            systemInfo.disk_total
                                                        )) *
                                                    100
                                                }%`,
                                            }"
                                        ></div>
                                    </div>
                                    <div
                                        class="flex justify-between items-center mt-1"
                                    >
                                        <span
                                            class="text-xs text-[var(--text-muted)]"
                                            >Free:
                                            {{
                                                systemInfo.disk_free || "N/A"
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Real-time Metrics Charts -->
                    <div
                        class="mt-6 pt-6 border-t border-[var(--border-default)]"
                    >
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- CPU Chart -->
                            <div
                                class="bg-[var(--surface-secondary)]/50 rounded-xl border border-[var(--border-default)] p-4 flex flex-col h-64"
                            >
                                <div
                                    class="flex justify-between items-center mb-4"
                                >
                                    <div class="flex items-center gap-3">
                                        <div>
                                            <h3
                                                class="font-medium text-[var(--text-primary)]"
                                            >
                                                CPU Load
                                            </h3>
                                            <p
                                                class="text-xs text-[var(--text-muted)]"
                                            >
                                                Real-time usage
                                            </p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="xs"
                                            @click="toggleCpuView"
                                            class="h-6 text-xs bg-[var(--surface-elevated)]"
                                            title="Toggle Core View"
                                        >
                                            {{
                                                cpuViewMode === "total"
                                                    ? "Cores"
                                                    : "Total"
                                            }}
                                        </Button>
                                    </div>
                                    <div
                                        class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-500 to-cyan-500"
                                    >
                                        {{ currentMetrics.cpu }}%
                                    </div>
                                </div>
                                <div class="flex-1 min-h-0">
                                    <Line
                                        :data="{
                                            labels: chartData.labels,
                                            datasets: chartData.datasets.filter(
                                                (d) =>
                                                    d.label !== 'Memory Usage %'
                                            ),
                                        }"
                                        :options="chartOptions"
                                    />
                                </div>
                            </div>

                            <!-- Memory Chart -->
                            <div
                                class="bg-[var(--surface-secondary)]/50 rounded-xl border border-[var(--border-default)] p-4 flex flex-col h-64"
                            >
                                <div
                                    class="flex justify-between items-center mb-4"
                                >
                                    <div>
                                        <h3
                                            class="font-medium text-[var(--text-primary)]"
                                        >
                                            Memory Usage
                                        </h3>
                                        <p
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{ currentMetrics.memory.used }} /
                                            {{ currentMetrics.memory.total }}
                                        </p>
                                    </div>
                                    <div
                                        class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-purple-500 to-pink-500"
                                    >
                                        {{ currentMetrics.memory.percent }}%
                                    </div>
                                </div>
                                <div class="flex-1 min-h-0">
                                    <Line
                                        :data="{
                                            labels: chartData.labels,
                                            datasets: chartData.datasets.filter(
                                                (d) =>
                                                    d.label === 'Memory Usage %'
                                            ),
                                        }"
                                        :options="chartOptions"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Cache Management -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center"
                    >
                        <Database class="w-4 h-4 text-blue-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Cache Management
                    </h3>
                </div>
                <div class="p-4 space-y-3">
                    <div
                        class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Application Cache
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Clear compiled views and config cache
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="clearCache('cache')"
                            :loading="isClearing.cache"
                        >
                            <RefreshCw class="w-4 h-4" />
                            Clear
                        </Button>
                    </div>
                    <div
                        class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                View Cache
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Clear compiled Blade templates
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="clearCache('views')"
                            :loading="isClearing.views"
                        >
                            <RefreshCw class="w-4 h-4" />
                            Clear
                        </Button>
                    </div>
                    <div
                        class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Session Data
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Clear all stored sessions (logs out all users)
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-[var(--color-error)] border-[var(--color-error)]"
                            @click="clearCache('sessions')"
                            :loading="isClearing.sessions"
                        >
                            <Trash2 class="w-4 h-4" />
                            Clear
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Log Management -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center"
                    >
                        <HardDrive class="w-4 h-4 text-orange-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Log Management
                    </h3>
                </div>
                <div class="p-4 space-y-3">
                    <div
                        class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Clear Old Logs
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Remove application logs older than 30 days
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="clearCache('logs')"
                            :loading="isClearing.logs"
                        >
                            <Trash2 class="w-4 h-4" />
                            Clear
                        </Button>
                    </div>
                    <div
                        class="flex items-center justify-between p-3 bg-[var(--surface-secondary)] rounded-lg"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                Prune Audit Logs
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Remove audit logs based on retention policy
                            </p>
                        </div>
                        <Button variant="outline" size="sm" disabled>
                            <Clock class="w-4 h-4" />
                            Scheduled
                        </Button>
                    </div>
                </div>
                <!-- Log Viewer Action -->
                <div class="px-4 pb-4">
                    <p
                        class="text-sm text-[var(--text-secondary)] mb-4 min-h-[40px]"
                    >
                        View application and system logs
                    </p>
                    <div class="flex flex-col gap-2">
                        <Button
                            variant="outline"
                            class="w-full justify-between group"
                            @click="router.push('/system/logs?tab=application')"
                        >
                            View Logs
                            <ChevronRight
                                class="w-4 h-4 text-[var(--text-muted)] group-hover:translate-x-1 transition-transform"
                            />
                        </Button>
                        <div
                            class="flex justify-between text-xs text-[var(--text-muted)] px-1"
                        >
                            <span
                                >{{ systemInfo.logs_count || 0 }} files
                                available</span
                            >
                            <span v-if="systemInfo.logs_size"
                                >Size: {{ systemInfo.logs_size }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <!-- Storage Usage -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center"
                    >
                        <HardDrive class="w-4 h-4 text-indigo-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Storage Usage
                    </h3>
                    <Button
                        v-if="storageStats"
                        variant="ghost"
                        size="icon"
                        class="ml-auto w-6 h-6"
                        @click="fetchStorageStats"
                        :disabled="isLoadingStorage"
                        title="Refresh Storage Stats"
                    >
                        <RefreshCw
                            class="w-3 h-3"
                            :class="{ 'animate-spin': isLoadingStorage }"
                        />
                    </Button>
                </div>
                <div class="p-4 space-y-4">
                    <div
                        v-if="isLoadingStorage && !storageStats"
                        class="flex justify-center p-4"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>
                    <template v-else-if="storageStats">
                        <!-- Local Storage -->
                        <div class="space-y-2">
                            <div
                                class="flex justify-between items-center text-sm"
                            >
                                <span class="text-[var(--text-secondary)]"
                                    >Local (Public)</span
                                >
                                <span
                                    class="font-medium text-[var(--text-primary)]"
                                    >{{
                                        storageStats.local.size_formatted
                                    }}</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center text-xs text-[var(--text-muted)]"
                            >
                                <span
                                    >{{
                                        storageStats.local.file_count
                                    }}
                                    files</span
                                >
                            </div>
                        </div>

                        <div class="h-px bg-[var(--border-default)]"></div>

                        <!-- S3 Storage -->
                        <div class="space-y-2">
                            <div
                                class="flex justify-between items-center text-sm"
                            >
                                <span class="text-[var(--text-secondary)]"
                                    >AWS S3 Bucket</span
                                >
                                <span
                                    class="font-medium text-[var(--text-primary)]"
                                    :class="{
                                        'text-[var(--text-muted)] italic':
                                            !storageStats.s3,
                                    }"
                                >
                                    {{
                                        storageStats.s3
                                            ? storageStats.s3.size_formatted
                                            : "Not Configured"
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="storageStats.s3"
                                class="flex justify-between items-center text-xs text-[var(--text-muted)]"
                            >
                                <span
                                    >{{
                                        storageStats.s3.file_count
                                    }}
                                    objects</span
                                >
                                <span
                                    class="opacity-75 truncate max-w-[150px]"
                                    >{{ storageStats.s3.path }}</span
                                >
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- External Services -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center justify-between"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center"
                        >
                            <Globe class="w-4 h-4 text-indigo-600" />
                        </div>
                        <h3 class="font-medium text-[var(--text-primary)]">
                            External Services
                        </h3>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-6 w-6 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                        @click="fetchExternalServices"
                        :disabled="isLoadingExternalServices"
                    >
                        <RefreshCw
                            class="w-3 h-3"
                            :class="{
                                'animate-spin': isLoadingExternalServices,
                            }"
                        />
                    </Button>
                </div>
                <div class="p-4 space-y-4">
                    <div
                        v-if="isLoadingExternalServices && !externalServices"
                        class="flex justify-center p-4"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>
                    <div v-else-if="externalServices" class="space-y-4">
                        <!-- Recaptcha -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <Shield
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                <div class="flex flex-col">
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >Google ReCaptcha</span
                                    >
                                    <span
                                        v-if="
                                            externalServices.recaptcha.message
                                        "
                                        class="text-xs text-[var(--color-error)]"
                                        >{{
                                            externalServices.recaptcha.message
                                        }}</span
                                    >
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    v-if="externalServices.recaptcha.latency"
                                    class="text-xs text-[var(--text-muted)]"
                                    >{{
                                        externalServices.recaptcha.latency
                                    }}ms</span
                                >
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                    :class="{
                                        'bg-green-500/10 text-green-500':
                                            externalServices.recaptcha
                                                .status === 'Operational',
                                        'bg-red-500/10 text-red-500':
                                            externalServices.recaptcha
                                                .status === 'Error' ||
                                            externalServices.recaptcha
                                                .status === 'Unreachable',
                                        'bg-yellow-500/10 text-yellow-500':
                                            externalServices.recaptcha
                                                .status === 'Not Configured',
                                    }"
                                >
                                    {{ externalServices.recaptcha.status }}
                                </span>
                            </div>
                        </div>

                        <div class="h-px bg-[var(--border-default)]"></div>

                        <!-- Twilio -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <MessageSquare
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                <div class="flex flex-col">
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >Twilio</span
                                    >
                                    <span
                                        v-if="externalServices.twilio.message"
                                        class="text-xs text-[var(--color-error)]"
                                        >{{
                                            externalServices.twilio.message
                                        }}</span
                                    >
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    v-if="externalServices.twilio.latency"
                                    class="text-xs text-[var(--text-muted)]"
                                    >{{
                                        externalServices.twilio.latency
                                    }}ms</span
                                >
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                    :class="{
                                        'bg-green-500/10 text-green-500':
                                            externalServices.twilio.status ===
                                            'Operational',
                                        'bg-red-500/10 text-red-500':
                                            externalServices.twilio.status.includes(
                                                'Error'
                                            ) ||
                                            externalServices.twilio.status ===
                                                'Unreachable' ||
                                            externalServices.twilio.status ===
                                                'Invalid Credentials',
                                        'bg-yellow-500/10 text-yellow-500':
                                            externalServices.twilio.status ===
                                            'Not Configured',
                                    }"
                                >
                                    {{ externalServices.twilio.status }}
                                </span>
                            </div>
                        </div>

                        <div class="h-px bg-[var(--border-default)]"></div>

                        <!-- Cloudflare -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <Cloud
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                <div class="flex flex-col">
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                        >Cloudflare</span
                                    >
                                    <span
                                        v-if="externalServices.cloudflare.info"
                                        class="text-xs text-[var(--text-muted)]"
                                        >{{
                                            externalServices.cloudflare.info
                                        }}</span
                                    >
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span
                                    v-if="externalServices.cloudflare.latency"
                                    class="text-xs text-[var(--text-muted)]"
                                    >{{
                                        externalServices.cloudflare.latency
                                    }}ms</span
                                >
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                    :class="{
                                        'bg-green-500/10 text-green-500':
                                            externalServices.cloudflare
                                                .status === 'Operational',
                                        'bg-red-500/10 text-red-500':
                                            externalServices.cloudflare
                                                .status === 'Error' ||
                                            externalServices.cloudflare
                                                .status === 'Unreachable',
                                        'bg-gray-500/10 text-gray-500':
                                            externalServices.cloudflare
                                                .status === 'Unknown',
                                    }"
                                >
                                    {{ externalServices.cloudflare.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PHP Configuration -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-teal-500/10 flex items-center justify-center"
                    >
                        <Wrench class="w-4 h-4 text-teal-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        PHP Configuration
                    </h3>
                </div>
                <div class="p-4 space-y-4">
                    <div
                        v-if="isLoadingPhp && !phpConfiguration"
                        class="flex justify-center p-4"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>
                    <template v-else-if="phpConfiguration">
                        <div class="space-y-3">
                            <div
                                class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >PHP Version</span
                                >
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >{{ phpConfiguration.version }}</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Memory Limit</span
                                >
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >{{ phpConfiguration.memory_limit }}</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Max Execution</span
                                >
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >{{
                                        phpConfiguration.max_execution_time
                                    }}s</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Upload Max</span
                                >
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >{{
                                        phpConfiguration.upload_max_filesize
                                    }}</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Opcache</span
                                >
                                <Badge
                                    :variant="
                                        phpConfiguration.opcache_enabled
                                            ? 'success'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        phpConfiguration.opcache_enabled
                                            ? "Enabled"
                                            : "Disabled"
                                    }}
                                </Badge>
                            </div>

                            <!-- Extensions -->
                            <div class="pt-2">
                                <p
                                    class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide mb-2"
                                >
                                    Loaded Extensions ({{
                                        phpConfiguration.extensions?.length ||
                                        0
                                    }})
                                </p>
                                <div
                                    class="flex flex-wrap gap-1 max-h-[150px] overflow-y-auto custom-scrollbar p-1"
                                >
                                    <span
                                        v-for="ext in phpConfiguration.extensions"
                                        :key="ext"
                                        class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-default)]"
                                    >
                                        {{ ext }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Database Health -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden flex flex-col h-full max-h-[500px]"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-rose-500/10 flex items-center justify-center"
                    >
                        <Database class="w-4 h-4 text-rose-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Database (Top Tables)
                    </h3>
                </div>
                <div class="px-4 pt-4 pb-0 flex-1 flex flex-col min-h-0">
                    <div
                        v-if="isLoadingDatabase && databaseHealth.length === 0"
                        class="flex justify-center p-4"
                    >
                        <Loader2
                            class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                        />
                    </div>
                    <div
                        v-else-if="databaseHealth.length === 0"
                        class="text-sm text-[var(--text-muted)] text-center italic py-4"
                    >
                        No table statistics available
                    </div>
                    <div
                        v-else
                        class="space-y-2 overflow-auto pr-2 custom-scrollbar pb-2 flex-1"
                    >
                        <div
                            v-for="table in databaseHealth"
                            :key="table.name"
                            class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg border border-transparent hover:border-[var(--border-default)] transition-colors"
                        >
                            <div class="flex flex-col min-w-0 pr-2">
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)] truncate"
                                    :title="table.name"
                                    >{{ table.name }}</span
                                >
                                <span class="text-xs text-[var(--text-muted)]"
                                    >{{
                                        table.rows_count?.toLocaleString() || 0
                                    }}
                                    rows</span
                                >
                            </div>
                            <span
                                class="text-xs font-mono bg-[var(--surface-primary)] px-2 py-1 rounded text-[var(--text-secondary)] shrink-0"
                            >
                                {{ table.size_mb }} MB
                            </span>
                        </div>
                    </div>
                </div>
                <!-- Pagination -->
                <div
                    v-if="
                        databaseHealth.length > 0 &&
                        databasePagination.last_page > 1
                    "
                    class="px-4 py-2 border-t border-[var(--border-default)] flex items-center justify-between text-xs bg-[var(--surface-secondary)]/30"
                >
                    <span class="text-[var(--text-muted)]">
                        {{
                            (databasePagination.current_page - 1) *
                                databasePagination.per_page +
                            1
                        }}-{{
                            Math.min(
                                databasePagination.current_page *
                                    databasePagination.per_page,
                                databasePagination.total
                            )
                        }}
                        of {{ databasePagination.total }}
                    </span>
                    <div class="flex gap-1">
                        <Button
                            variant="ghost"
                            size="xs"
                            :disabled="databasePagination.current_page === 1"
                            @click="
                                fetchDatabaseHealth(
                                    databasePagination.current_page - 1
                                )
                            "
                        >
                            <ChevronLeft class="w-3 h-3" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="xs"
                            :disabled="
                                databasePagination.current_page ===
                                databasePagination.last_page
                            "
                            @click="
                                fetchDatabaseHealth(
                                    databasePagination.current_page + 1
                                )
                            "
                        >
                            <ChevronRight class="w-3 h-3" />
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Backup Management -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden md:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex flex-col sm:flex-row items-center justify-between gap-4"
                >
                    <div class="flex items-center gap-3 w-full sm:w-auto">
                        <div
                            class="w-8 h-8 rounded-lg bg-violet-500/10 flex items-center justify-center shrink-0"
                        >
                            <Archive class="w-4 h-4 text-violet-600" />
                        </div>
                        <h3 class="font-medium text-[var(--text-primary)]">
                            Backups
                        </h3>

                        <div
                            class="h-6 w-px bg-[var(--border-default)] mx-1"
                        ></div>

                        <!-- View Toggle -->
                        <div
                            class="flex items-center bg-[var(--surface-secondary)] rounded-lg p-0.5 border border-[var(--border-default)]"
                        >
                            <button
                                @click="backupsViewMode = 'list'"
                                class="p-1 rounded-md transition-all"
                                :class="
                                    backupsViewMode === 'list'
                                        ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                title="List View"
                            >
                                <ListIcon class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="backupsViewMode = 'grid'"
                                class="p-1 rounded-md transition-all"
                                :class="
                                    backupsViewMode === 'grid'
                                        ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)]'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                title="Grid View"
                            >
                                <LayoutGrid class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2 w-full sm:w-auto justify-end"
                    >
                        <!-- Bulk Actions -->
                        <div
                            v-if="selectedBackups.length > 0"
                            class="flex items-center gap-2 mr-2 animate-in fade-in zoom-in duration-200"
                        >
                            <span
                                class="text-xs font-medium text-[var(--text-secondary)]"
                                >{{ selectedBackups.length }} selected</span
                            >
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-[var(--color-error)] hover:bg-[var(--color-error)]/10"
                                @click="handleBulkDelete"
                                :loading="isBulkDeleting"
                            >
                                <Trash2 class="w-3.5 h-3.5 mr-1" />
                                Delete
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="openSecureDownloadModal"
                            >
                                <Download class="w-3.5 h-3.5 mr-1" />
                                Download
                            </Button>
                        </div>

                        <!-- New Backup Dropdown/Buttons -->
                        <div
                            class="flex items-center bg-[var(--surface-secondary)] rounded-lg p-0.5 border border-[var(--border-default)]"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-xs"
                                :disabled="isCreatingBackup"
                                @click="createBackup('both')"
                            >
                                Full
                            </Button>
                            <div
                                class="w-px h-3 bg-[var(--border-default)]"
                            ></div>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-xs"
                                :disabled="isCreatingBackup"
                                @click="createBackup('db')"
                            >
                                DB
                            </Button>
                            <div
                                class="w-px h-3 bg-[var(--border-default)]"
                            ></div>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-xs"
                                :disabled="isCreatingBackup"
                                @click="createBackup('files')"
                            >
                                Files
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="p-0">
                    <div
                        v-if="isLoadingBackups"
                        class="flex justify-center p-8"
                    >
                        <Loader2
                            class="w-8 h-8 animate-spin text-[var(--text-muted)]"
                        />
                    </div>
                    <div
                        v-else-if="backups.length === 0"
                        class="text-center py-12"
                    >
                        <div
                            class="w-12 h-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mx-auto mb-3"
                        >
                            <Archive class="w-6 h-6 text-[var(--text-muted)]" />
                        </div>
                        <p class="text-[var(--text-muted)]">
                            No backups found.
                        </p>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            Create your first backup to verify configuration.
                        </p>
                    </div>
                    <div v-else class="flex flex-col">
                        <!-- List View -->
                        <div
                            v-if="backupsViewMode === 'list'"
                            class="overflow-x-auto"
                        >
                            <table class="w-full text-sm text-left">
                                <thead
                                    class="text-xs text-[var(--text-muted)] uppercase bg-[var(--surface-secondary)] border-b border-[var(--border-default)]"
                                >
                                    <tr>
                                        <th class="px-4 py-3 w-8">
                                            <Checkbox
                                                :checked="
                                                    selectedBackups.length ===
                                                        backups.length &&
                                                    backups.length > 0
                                                "
                                                @update:checked="
                                                    selectAllBackups
                                                "
                                            />
                                        </th>
                                        <th class="px-6 py-3 font-medium">
                                            Name
                                        </th>
                                        <th class="px-6 py-3 font-medium">
                                            Size
                                        </th>
                                        <th class="px-6 py-3 font-medium">
                                            Date
                                        </th>
                                        <th
                                            class="px-6 py-3 font-medium text-right"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody
                                    class="divide-y divide-[var(--border-default)]"
                                >
                                    <tr
                                        v-for="backup in backups"
                                        :key="backup.path"
                                        class="hover:bg-[var(--surface-secondary)]/50 transition-colors"
                                    >
                                        <td class="px-4 py-3">
                                            <Checkbox
                                                :checked="
                                                    selectedBackups.includes(
                                                        backup.path
                                                    )
                                                "
                                                @update:checked="
                                                    toggleBackupSelection(
                                                        backup.path
                                                    )
                                                "
                                            />
                                        </td>
                                        <td
                                            class="px-6 py-3 font-medium text-[var(--text-primary)]"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <FileText
                                                    class="w-4 h-4 text-[var(--text-muted)]"
                                                />
                                                {{ backup.name }}
                                            </div>
                                        </td>
                                        <td
                                            class="px-6 py-3 text-[var(--text-secondary)]"
                                        >
                                            {{ backup.size }}
                                        </td>
                                        <td
                                            class="px-6 py-3 text-[var(--text-secondary)]"
                                        >
                                            {{ backup.created_at }}
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            <div
                                                class="flex items-center justify-end gap-2"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="xs"
                                                    @click="
                                                        downloadBackup(
                                                            backup.path
                                                        )
                                                    "
                                                    title="Secure Download"
                                                >
                                                    <Lock
                                                        class="w-3.5 h-3.5 text-[var(--text-secondary)]"
                                                    />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="xs"
                                                    class="text-[var(--color-error)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10"
                                                    @click="
                                                        deleteBackup(
                                                            backup.path
                                                        )
                                                    "
                                                    :loading="
                                                        backupActionRunning ===
                                                        backup.path
                                                    "
                                                    title="Delete"
                                                >
                                                    <Trash2
                                                        class="w-3.5 h-3.5"
                                                    />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Grid View -->
                        <div
                            v-else
                            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 p-4"
                        >
                            <div
                                v-for="backup in backups"
                                :key="backup.path"
                                class="relative group bg-[var(--surface-secondary)]/30 border border-[var(--border-default)] rounded-xl p-4 hover:border-indigo-500/50 hover:shadow-sm transition-all cursor-pointer"
                                :class="{
                                    'ring-2 ring-indigo-500/20 border-indigo-500':
                                        selectedBackups.includes(backup.path),
                                }"
                                @click="toggleBackupSelection(backup.path)"
                            >
                                <div class="absolute top-3 right-3 z-10">
                                    <Checkbox
                                        :checked="
                                            selectedBackups.includes(
                                                backup.path
                                            )
                                        "
                                        @click.stop
                                        @update:checked="
                                            toggleBackupSelection(backup.path)
                                        "
                                    />
                                </div>
                                <div class="flex flex-col gap-3">
                                    <div
                                        class="w-10 h-10 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] flex items-center justify-center"
                                    >
                                        <FileText
                                            class="w-5 h-5 text-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <h4
                                            class="font-medium text-sm text-[var(--text-primary)] truncate mb-1"
                                            :title="backup.name"
                                        >
                                            {{ backup.name }}
                                        </h4>
                                        <div
                                            class="flex items-center gap-2 text-xs text-[var(--text-muted)]"
                                        >
                                            <span>{{ backup.size }}</span>
                                            <span>•</span>
                                            <span>{{ backup.created_at }}</span>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center gap-2 mt-2 pt-3 border-t border-[var(--border-default)]"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="flex-1 h-8 text-xs"
                                            @click.stop="
                                                downloadBackup(backup.path)
                                            "
                                        >
                                            <Lock class="w-3 h-3 mr-1.5" />
                                            Download
                                        </Button>
                                        <div
                                            class="w-px h-4 bg-[var(--border-default)]"
                                        ></div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="flex-1 h-8 text-xs text-[var(--color-error)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10"
                                            @click.stop="
                                                deleteBackup(backup.path)
                                            "
                                        >
                                            <Trash2 class="w-3 h-3 mr-1.5" />
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div
                            class="px-4 py-3 border-t border-[var(--border-default)] flex flex-col sm:flex-row items-center justify-between gap-4 text-xs"
                        >
                            <div class="flex items-center gap-2">
                                <span class="text-[var(--text-muted)]"
                                    >Rows per page:</span
                                >
                                <select
                                    v-model="backupPagination.per_page"
                                    @change="fetchBackups(1)"
                                    class="bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded px-2 py-1 text-[var(--text-primary)] focus:ring-1 focus:ring-indigo-500 outline-none"
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

                            <div class="flex items-center gap-2 sm:gap-4">
                                <span class="text-[var(--text-muted)]">
                                    {{
                                        (backupPagination.current_page - 1) *
                                            backupPagination.per_page +
                                        1
                                    }}-{{
                                        Math.min(
                                            backupPagination.current_page *
                                                backupPagination.per_page,
                                            backupPagination.total
                                        )
                                    }}
                                    of {{ backupPagination.total }}
                                </span>
                                <div class="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="xs"
                                        :disabled="
                                            backupPagination.current_page === 1
                                        "
                                        @click="
                                            fetchBackups(
                                                backupPagination.current_page -
                                                    1
                                            )
                                        "
                                    >
                                        <ChevronLeft class="w-3 h-3 md:mr-1" />
                                        <span class="hidden md:inline"
                                            >Prev</span
                                        >
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="xs"
                                        :disabled="
                                            backupPagination.current_page ===
                                            backupPagination.last_page
                                        "
                                        @click="
                                            fetchBackups(
                                                backupPagination.current_page +
                                                    1
                                            )
                                        "
                                    >
                                        <span class="hidden md:inline"
                                            >Next</span
                                        >
                                        <ChevronRight class="w-3 h-3 md:ml-1" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Queue Management -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden md:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-cyan-500/10 flex items-center justify-center"
                    >
                        <Server class="w-4 h-4 text-cyan-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Queue Management
                    </h3>
                </div>
                <div class="p-4 space-y-4">
                    <!-- Queue Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div
                            class="bg-[var(--surface-secondary)] rounded-lg p-4"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-sm font-medium text-[var(--text-secondary)]"
                                    >Pending Jobs</span
                                >
                                <Clock class="w-4 h-4 text-blue-600" />
                            </div>
                            <div
                                class="text-2xl font-bold text-[var(--text-primary)]"
                            >
                                {{ queueStats?.pending ?? 0 }}
                            </div>
                        </div>

                        <div
                            class="bg-[var(--surface-secondary)] rounded-lg p-4"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-sm font-medium text-[var(--text-secondary)]"
                                    >Failed Jobs</span
                                >
                                <AlertTriangle
                                    class="w-4 h-4 text-[var(--color-error)]"
                                />
                            </div>
                            <div
                                class="text-2xl font-bold text-[var(--color-error)]"
                            >
                                {{ queueStats?.failed ?? 0 }}
                            </div>
                        </div>

                        <div
                            class="bg-[var(--surface-secondary)] rounded-lg p-4"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-sm font-medium text-[var(--text-secondary)]"
                                    >Connection</span
                                >
                                <CheckCircle class="w-4 h-4 text-green-600" />
                            </div>
                            <div
                                class="text-sm font-medium text-[var(--text-primary)]"
                            >
                                {{ queueStats?.connection ?? "N/A" }}
                            </div>
                        </div>
                    </div>

                    <!-- Failed Jobs Table -->
                    <!-- Queue Jobs Tabs -->
                    <div class="border-t border-[var(--border-default)] pt-4">
                        <div class="flex items-center justify-between mb-4">
                            <div
                                class="flex items-center gap-2 bg-[var(--surface-secondary)] p-1 rounded-lg"
                            >
                                <button
                                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                                    :class="
                                        activeQueueTab === 'pending'
                                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                                    "
                                    @click="activeQueueTab = 'pending'"
                                >
                                    Pending Jobs
                                    <span
                                        v-if="pendingJobs.length"
                                        class="ml-1.5 px-1.5 py-0.5 rounded-full text-xs bg-blue-500/10 text-blue-500"
                                        >{{ pendingJobs.length }}</span
                                    >
                                </button>
                                <button
                                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                                    :class="
                                        activeQueueTab === 'failed'
                                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                                    "
                                    @click="activeQueueTab = 'failed'"
                                >
                                    Failed Jobs
                                    <span
                                        v-if="failedJobs.length"
                                        class="ml-1.5 px-1.5 py-0.5 rounded-full text-xs bg-[var(--color-error)]/10 text-[var(--color-error)]"
                                        >{{ failedJobs.length }}</span
                                    >
                                </button>
                                <button
                                    class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                                    :class="
                                        activeQueueTab === 'completed'
                                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                                    "
                                    @click="activeQueueTab = 'completed'"
                                >
                                    Completed Jobs
                                </button>
                            </div>

                            <div class="flex items-center gap-2">
                                <template v-if="activeQueueTab === 'failed'">
                                    <Button
                                        v-if="failedJobs.length > 0"
                                        variant="outline"
                                        size="sm"
                                        @click="retryAllJobs"
                                        :loading="
                                            queueActionRunning === 'retry-all'
                                        "
                                    >
                                        <RefreshCw class="w-3.5 h-3.5 mr-1.5" />
                                        Retry All
                                    </Button>
                                    <Button
                                        v-if="failedJobs.length > 0"
                                        variant="danger"
                                        size="sm"
                                        @click="flushFailedJobs"
                                        :loading="
                                            queueActionRunning === 'flush'
                                        "
                                    >
                                        <Trash2 class="w-3.5 h-3.5 mr-1.5" />
                                        Flush
                                    </Button>
                                </template>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="h-8 w-8"
                                    @click="fetchQueueData"
                                    :disabled="isLoadingQueue"
                                >
                                    <RefreshCw
                                        class="w-3.5 h-3.5"
                                        :class="{
                                            'animate-spin': isLoadingQueue,
                                        }"
                                    />
                                </Button>
                            </div>
                        </div>

                        <div v-if="isLoadingQueue" class="space-y-2">
                            <div
                                v-for="i in 3"
                                :key="i"
                                class="h-12 bg-[var(--surface-secondary)] rounded animate-pulse"
                            ></div>
                        </div>

                        <!-- Pending Jobs Table -->
                        <div v-if="activeQueueTab === 'pending'">
                            <div
                                v-if="pendingJobs.length === 0"
                                class="text-center py-8 text-[var(--text-muted)]"
                            >
                                <CheckCircle
                                    class="w-8 h-8 mx-auto mb-2 text-green-500 opacity-50"
                                />
                                <p class="text-sm">No pending jobs in queue</p>
                            </div>
                            <div v-else class="overflow-auto max-h-[400px]">
                                <table class="w-full text-sm">
                                    <thead
                                        class="text-xs text-[var(--text-muted)] uppercase border-b border-[var(--border-default)]"
                                    >
                                        <tr>
                                            <th class="text-left py-2 px-2">
                                                ID
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Job
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Queue
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Attempts
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Available At
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-[var(--border-default)]"
                                    >
                                        <tr
                                            v-for="job in pendingJobs"
                                            :key="job.id"
                                            class="hover:bg-[var(--surface-secondary)] transition-colors"
                                        >
                                            <td
                                                class="py-2 px-2 text-[var(--text-muted)]"
                                            >
                                                {{ job.id }}
                                            </td>
                                            <td class="py-2 px-2">
                                                <span
                                                    class="text-[var(--text-primary)] font-medium"
                                                    >{{ job.command }}</span
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <span
                                                    class="px-2 py-0.5 text-xs rounded-full bg-blue-500/10 text-blue-400"
                                                    >{{ job.queue }}</span
                                                >
                                            </td>
                                            <td
                                                class="py-2 px-2 text-[var(--text-muted)]"
                                            >
                                                {{ job.attempts }}
                                            </td>
                                            <td
                                                class="py-2 px-2 text-[var(--text-muted)]"
                                            >
                                                {{ job.available_at }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Failed Jobs Table -->
                        <div v-else-if="activeQueueTab === 'failed'">
                            <div
                                v-if="failedJobs.length === 0"
                                class="text-center py-8 text-[var(--text-muted)]"
                            >
                                <CheckCircle
                                    class="w-8 h-8 mx-auto mb-2 text-green-500 opacity-50"
                                />
                                <p class="text-sm">No failed jobs found</p>
                            </div>
                            <div v-else class="overflow-auto max-h-[400px]">
                                <table class="w-full text-sm">
                                    <thead
                                        class="text-xs text-[var(--text-muted)] uppercase border-b border-[var(--border-default)]"
                                    >
                                        <tr>
                                            <th class="text-left py-2 px-2">
                                                Job
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Queue
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Failed At
                                            </th>
                                            <th class="text-right py-2 px-2">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-[var(--border-default)]"
                                    >
                                        <tr
                                            v-for="job in failedJobs"
                                            :key="job.id"
                                        >
                                            <td class="py-3 px-2">
                                                <div
                                                    class="font-medium text-[var(--text-primary)] text-xs"
                                                >
                                                    {{
                                                        job.payload
                                                            ?.displayName ||
                                                        "Unknown Job"
                                                    }}
                                                </div>
                                                <div
                                                    class="text-xs text-[var(--text-muted)] font-mono mt-0.5 truncate max-w-xs"
                                                >
                                                    {{ job.uuid }}
                                                </div>
                                            </td>
                                            <td
                                                class="py-3 px-2 text-[var(--text-secondary)] text-xs"
                                            >
                                                {{ job.queue }}
                                            </td>
                                            <td
                                                class="py-3 px-2 text-[var(--text-secondary)] text-xs"
                                            >
                                                {{
                                                    formatLastRun(job.failed_at)
                                                }}
                                            </td>
                                            <td class="py-3 px-2 text-right">
                                                <div
                                                    class="flex items-center justify-end gap-1.5"
                                                >
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        class="h-7 text-xs"
                                                        @click="
                                                            retryJob(job.uuid)
                                                        "
                                                        :loading="
                                                            queueActionRunning ===
                                                            `retry-${job.uuid}`
                                                        "
                                                    >
                                                        Retry
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-7 w-7 text-[var(--color-error)]"
                                                        @click="
                                                            forgetJob(job.uuid)
                                                        "
                                                        :loading="
                                                            queueActionRunning ===
                                                            `forget-${job.uuid}`
                                                        "
                                                    >
                                                        <Trash2
                                                            class="w-3.5 h-3.5"
                                                        />
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Completed Jobs Table -->
                        <div v-else-if="activeQueueTab === 'completed'">
                            <div
                                v-if="completedJobs.length === 0"
                                class="text-center py-8 text-[var(--text-muted)]"
                            >
                                <div
                                    class="w-8 h-8 mx-auto mb-2 text-[var(--text-muted)] opacity-50 flex items-center justify-center border-2 border-[var(--text-muted)] rounded-full"
                                >
                                    <span class="text-lg font-bold">0</span>
                                </div>
                                <p class="text-sm">No completed jobs found</p>
                            </div>
                            <div v-else class="overflow-auto max-h-[400px]">
                                <table class="w-full text-sm">
                                    <thead
                                        class="text-xs text-[var(--text-muted)] uppercase border-b border-[var(--border-default)]"
                                    >
                                        <tr>
                                            <th class="text-left py-2 px-2">
                                                Job
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Completed At
                                            </th>
                                            <th class="text-left py-2 px-2">
                                                Runtime
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="divide-y divide-[var(--border-default)]"
                                    >
                                        <tr
                                            v-for="job in completedJobs"
                                            :key="job.id"
                                        >
                                            <td class="py-3 px-2">
                                                <div
                                                    class="font-medium text-[var(--text-primary)] text-xs"
                                                >
                                                    {{ job.name }}
                                                </div>
                                                <div
                                                    class="text-xs text-[var(--text-muted)] font-mono mt-0.5 truncate max-w-xs"
                                                >
                                                    {{ job.id }}
                                                </div>
                                            </td>
                                            <td
                                                class="py-3 px-2 text-[var(--text-secondary)] text-xs"
                                            >
                                                {{
                                                    new Date(
                                                        job.completed_at * 1000
                                                    ).toLocaleString()
                                                }}
                                            </td>
                                            <td
                                                class="py-3 px-2 text-[var(--text-secondary)] text-xs"
                                            >
                                                {{
                                                    (
                                                        job.completed_at -
                                                        job.reserved_at
                                                    ).toFixed(2)
                                                }}s
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scheduled Tasks -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden md:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center justify-between"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="w-8 h-8 rounded-lg bg-purple-500/10 flex items-center justify-center"
                        >
                            <Clock class="w-4 h-4 text-purple-600" />
                        </div>
                        <h3 class="font-medium text-[var(--text-primary)]">
                            Scheduled Tasks
                        </h3>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="fetchScheduledTasks"
                        :disabled="isLoadingTasks"
                    >
                        <RefreshCw
                            class="w-4 h-4"
                            :class="{ 'animate-spin': isLoadingTasks }"
                        />
                    </Button>
                </div>
                <div class="p-4 overflow-auto max-h-[400px]">
                    <table class="w-full text-sm">
                        <thead
                            class="text-xs text-[var(--text-muted)] uppercase"
                        >
                            <tr>
                                <th class="text-left py-2">Task</th>
                                <th class="text-left py-2">Schedule</th>
                                <th class="text-left py-2">Last Run</th>
                                <th class="text-left py-2">Status</th>
                                <th class="text-right py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr v-for="task in scheduledTasks" :key="task.name">
                                <td class="py-3 text-[var(--text-primary)]">
                                    {{ task.description }}
                                </td>
                                <td class="py-3 text-[var(--text-secondary)]">
                                    {{ task.schedule }}
                                </td>
                                <td class="py-3 text-[var(--text-secondary)]">
                                    {{ formatLastRun(task.last_run) }}
                                </td>
                                <td class="py-3">
                                    <span
                                        v-if="task.status === 'running'"
                                        class="inline-flex items-center gap-1 text-blue-600 text-xs"
                                    >
                                        <Loader2 class="w-3 h-3 animate-spin" />
                                        Running
                                    </span>
                                    <span
                                        v-else-if="task.status === 'failed'"
                                        class="inline-flex items-center gap-1 text-[var(--color-error)] text-xs"
                                    >
                                        <XCircle class="w-3 h-3" /> Failed
                                    </span>
                                    <span
                                        v-else-if="task.status === 'success'"
                                        class="inline-flex items-center gap-1 text-green-600 text-xs"
                                    >
                                        <CheckCircle class="w-3 h-3" /> Success
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-[var(--text-muted)] text-xs"
                                    >
                                        <Clock class="w-3 h-3" /> Pending
                                    </span>
                                </td>
                                <td class="py-3 text-right">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7"
                                        @click="runTask(task.name)"
                                        :disabled="runningTask === task.name"
                                    >
                                        <Loader2
                                            v-if="runningTask === task.name"
                                            class="w-3.5 h-3.5 animate-spin"
                                        />
                                        <Play v-else class="w-3.5 h-3.5" />
                                    </Button>
                                </td>
                            </tr>
                            <tr
                                v-if="
                                    scheduledTasks.length === 0 &&
                                    !isLoadingTasks
                                "
                            >
                                <td
                                    colspan="5"
                                    class="py-8 text-center text-[var(--text-muted)]"
                                >
                                    No scheduled tasks found
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Server Information -->

            <!-- Cache Statistics -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden md:col-span-2"
            >
                <div
                    class="p-4 border-b border-[var(--border-default)] flex items-center gap-3"
                >
                    <div
                        class="w-8 h-8 rounded-lg bg-cyan-500/10 flex items-center justify-center"
                    >
                        <Database class="w-4 h-4 text-cyan-600" />
                    </div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Cache Statistics
                    </h3>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Cache Configuration -->
                        <div class="space-y-3">
                            <h4
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide"
                            >
                                Configuration
                            </h4>
                            <div class="space-y-2">
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Driver</span
                                    >
                                    <Badge
                                        :variant="
                                            systemInfo.cache_driver === 'Redis'
                                                ? 'default'
                                                : 'outline'
                                        "
                                        class="text-xs"
                                    >
                                        {{
                                            systemInfo.cache_driver || "Unknown"
                                        }}
                                    </Badge>
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Status</span
                                    >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="w-2 h-2 rounded-full"
                                            :class="
                                                systemInfo.cache_status ===
                                                    'Connected' ||
                                                systemInfo.cache_status ===
                                                    'Active'
                                                    ? 'bg-green-500 animate-pulse'
                                                    : 'bg-red-500'
                                            "
                                        ></span>
                                        <span
                                            class="text-sm font-medium text-[var(--text-primary)]"
                                            >{{
                                                systemInfo.cache_status ||
                                                "Unknown"
                                            }}</span
                                        >
                                    </div>
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Active Users</span
                                    >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"
                                            v-if="
                                                (systemInfo.reverb_connections ||
                                                    0) > 0
                                            "
                                        ></span>
                                        <span
                                            class="text-sm font-medium text-[var(--text-primary)]"
                                        >
                                            {{
                                                systemInfo.reverb_connections ||
                                                0
                                            }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    class="flex justify-between items-center p-2 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <span
                                        class="text-sm text-[var(--text-secondary)]"
                                        >Total Keys</span
                                    >
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{
                                            typeof systemInfo.cache_keys ===
                                            "number"
                                                ? systemInfo.cache_keys.toLocaleString()
                                                : systemInfo.cache_keys || "N/A"
                                        }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Redis Metrics (only shown if Redis is active) -->
                        <div
                            v-if="
                                systemInfo.cache_driver === 'Redis' &&
                                systemInfo.cache_status === 'Connected'
                            "
                            class="space-y-3"
                        >
                            <h4
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide"
                            >
                                Redis Metrics
                            </h4>
                            <div class="space-y-2">
                                <div
                                    class="p-2.5 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div
                                        class="flex justify-between items-center mb-1"
                                    >
                                        <span
                                            class="text-sm text-[var(--text-secondary)]"
                                            >Memory Usage</span
                                        >
                                        <span
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{
                                                systemInfo.cache_memory_used ||
                                                "N/A"
                                            }}
                                            /
                                            {{
                                                systemInfo.cache_memory_limit ||
                                                "N/A"
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        Peak:
                                        {{
                                            systemInfo.cache_memory_peak ||
                                            "N/A"
                                        }}
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div
                                        class="p-2 bg-[var(--surface-secondary)] rounded-lg"
                                    >
                                        <div
                                            class="text-xs text-[var(--text-muted)] mb-0.5"
                                        >
                                            Cache Hits
                                        </div>
                                        <div
                                            class="text-sm font-semibold text-green-600"
                                        >
                                            {{ systemInfo.cache_hits || "0" }}
                                        </div>
                                    </div>
                                    <div
                                        class="p-2 bg-[var(--surface-secondary)] rounded-lg"
                                    >
                                        <div
                                            class="text-xs text-[var(--text-muted)] mb-0.5"
                                        >
                                            Cache Misses
                                        </div>
                                        <div
                                            class="text-sm font-semibold text-orange-600"
                                        >
                                            {{ systemInfo.cache_misses || "0" }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fallback for non-Redis drivers -->
                        <div
                            v-else
                            class="flex items-center justify-center text-center p-6"
                        >
                            <div class="text-sm text-[var(--text-muted)]">
                                <Database
                                    class="w-8 h-8 mx-auto mb-2 opacity-40"
                                />
                                Detailed metrics available for Redis only
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance Mode Modal -->
        <Modal
            :open="showMaintenanceModal"
            @update:open="showMaintenanceModal = $event"
            title="Enable Maintenance Mode"
            description="This will make the application inaccessible to all users except those with the bypass secret."
            size="md"
        >
            <div class="space-y-4">
                <div
                    class="flex items-center gap-2 p-3 bg-[var(--color-warning)]/10 rounded-lg text-[var(--color-warning)]"
                >
                    <AlertTriangle class="w-5 h-5 shrink-0" />
                    <p class="text-sm">
                        All users will be logged out and unable to access the
                        application.
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Password
                        <span class="text-[var(--color-error)]">*</span>
                    </label>
                    <Input
                        v-model="maintenanceForm.password"
                        type="password"
                        placeholder="Enter your password to confirm"
                        :class="{
                            'border-[var(--color-error)]':
                                maintenanceFormErrors.password,
                        }"
                    />
                    <p
                        v-if="maintenanceFormErrors.password"
                        class="text-xs text-[var(--color-error)]"
                    >
                        {{ maintenanceFormErrors.password[0] }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Reason for Maintenance
                        <span class="text-[var(--color-error)]">*</span>
                    </label>
                    <textarea
                        v-model="maintenanceForm.reason"
                        placeholder="e.g., Deploying version 2.5.0 with new features..."
                        rows="3"
                        class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] focus:border-transparent resize-none"
                        :class="{
                            'border-[var(--color-error)]':
                                maintenanceFormErrors.reason,
                        }"
                    />
                    <p
                        v-if="maintenanceFormErrors.reason"
                        class="text-xs text-[var(--color-error)]"
                    >
                        {{ maintenanceFormErrors.reason[0] }}
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Bypass Secret
                        <span class="text-[var(--text-muted)]">(optional)</span>
                    </label>
                    <Input
                        v-model="maintenanceForm.secret"
                        placeholder="e.g., my-secret-bypass"
                    />
                    <p class="text-xs text-[var(--text-muted)]">
                        Users can access via: /{{
                            maintenanceForm.secret || "secret"
                        }}
                    </p>
                </div>
            </div>

            <template #footer>
                <Button variant="outline" @click="showMaintenanceModal = false">
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    @click="enableMaintenance"
                    :loading="isEnablingMaintenance"
                    :disabled="
                        !maintenanceForm.password || !maintenanceForm.reason
                    "
                >
                    Enable Maintenance
                </Button>
            </template>
        </Modal>

        <!-- Sessions Clear Modal -->
        <Modal
            :open="showSessionsModal"
            @update:open="showSessionsModal = $event"
            title="Clear All Sessions"
            description="This will log out ALL users including yourself. You will need to log in again."
            size="sm"
        >
            <div class="space-y-4">
                <div
                    class="flex items-center gap-2 p-3 bg-[var(--color-error)]/10 rounded-lg text-[var(--color-error)]"
                >
                    <XCircle class="w-5 h-5 shrink-0" />
                    <p class="text-sm">
                        This action cannot be undone. All users will be logged
                        out.
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Password
                        <span class="text-[var(--color-error)]">*</span>
                    </label>
                    <Input
                        v-model="sessionsPassword"
                        type="password"
                        placeholder="Enter your password to confirm"
                        :class="{
                            'border-[var(--color-error)]':
                                sessionsPasswordError,
                        }"
                    />
                    <p
                        v-if="sessionsPasswordError"
                        class="text-xs text-[var(--color-error)]"
                    >
                        {{ sessionsPasswordError }}
                    </p>
                </div>
            </div>

            <template #footer>
                <Button variant="outline" @click="showSessionsModal = false">
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    @click="clearSessionsWithPassword"
                    :loading="isClearingSessions"
                    :disabled="!sessionsPassword"
                >
                    Clear All Sessions
                </Button>
            </template>
        </Modal>

        <!-- Log Viewer Modal -->
        <Modal
            :open="showLogsModal"
            @update:open="showLogsModal = $event"
            title="Laravel System Logs"
            description="Viewing the last 100 lines of storage/logs/laravel.log"
            size="2xl"
        >
            <div class="space-y-4">
                <div
                    class="flex items-center gap-2 p-3 bg-blue-500/10 rounded-lg text-blue-500"
                >
                    <AlertTriangle class="w-5 h-5 shrink-0" />
                    <p class="text-xs">
                        Reading from local file system. If your environment uses
                        a different logging driver (e.g., CloudWatch,
                        Papertrail), this file may be empty or incomplete.
                    </p>
                </div>

                <div
                    v-if="isLoadingLogs && !logData"
                    class="flex justify-center p-8"
                >
                    <Loader2
                        class="w-8 h-8 animate-spin text-[var(--text-muted)]"
                    />
                </div>
                <div
                    v-else-if="
                        logData && logData.content && logData.content.length > 0
                    "
                    class="bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg p-3 font-mono text-xs overflow-auto max-h-[500px] whitespace-pre-wrap text-[var(--text-secondary)] custom-scrollbar"
                >
                    <div
                        v-for="(line, index) in logData.content"
                        :key="index"
                        class="border-b border-[var(--border-default)]/50 last:border-0 py-0.5 px-1 hover:bg-[var(--surface-secondary)]/50"
                    >
                        {{ line }}
                    </div>
                </div>
                <div v-else class="text-center py-8 text-[var(--text-muted)]">
                    Log file is empty or not readable.
                </div>
            </div>

            <template #footer>
                <div class="flex justify-between w-full">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="fetchLogs"
                        :disabled="isLoadingLogs"
                    >
                        <RefreshCw
                            class="w-4 h-4 mr-2"
                            :class="{ 'animate-spin': isLoadingLogs }"
                        />
                        Refresh
                    </Button>
                    <Button variant="outline" @click="showLogsModal = false">
                        Close
                    </Button>
                </div>
            </template>
        </Modal>

        <!-- Secure Download Modal -->
        <Modal
            :open="showSecureDownloadModal"
            @update:open="showSecureDownloadModal = $event"
            title="Secure Download"
            description="For security, please confirm your password and provide a reason for downloading backup(s)."
            size="md"
        >
            <div class="space-y-4">
                <div
                    class="flex items-center gap-2 p-3 bg-blue-500/10 rounded-lg text-blue-500"
                >
                    <Lock class="w-5 h-5 shrink-0" />
                    <p class="text-sm">
                        A password to unzip the downloaded file(s) will be sent
                        to your email address.
                    </p>
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Password
                        <span class="text-[var(--color-error)]">*</span>
                    </label>
                    <Input
                        v-model="secureDownloadForm.password"
                        type="password"
                        placeholder="Enter your password"
                    />
                </div>

                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Reason
                        <span class="text-[var(--color-error)]">*</span>
                    </label>
                    <Textarea
                        v-model="secureDownloadForm.reason"
                        placeholder="e.g. Auditing data for compliance..."
                        :rows="3"
                    />
                </div>
            </div>

            <template #footer>
                <Button
                    variant="outline"
                    @click="showSecureDownloadModal = false"
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    @click="handleSecureDownload"
                    :loading="isSecureDownloading"
                    :disabled="
                        !secureDownloadForm.password ||
                        !secureDownloadForm.reason
                    "
                >
                    <Download class="w-4 h-4 mr-2" />
                    Download & Email Password
                </Button>
            </template>
        </Modal>
    </div>
</template>
