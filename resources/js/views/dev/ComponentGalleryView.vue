<script setup>
import { ref, computed } from "vue";
import { useToast } from "@/composables/useToast.ts";
import {
    Card,
    Button,
    Badge,
    Avatar,
    Modal,
    Drawer,
    Input,
    Alert,
    Checkbox,
    Switch,
    Tooltip,
    Dropdown,
    DropdownItem,
    DropdownLabel,
    DropdownSeparator,
    TagInput,
    StatusBadge,
    SelectFilter,
    SearchInput,
} from "@/components/ui";
import {
    Sparkles,
    PanelRight,
    CheckCircle,
    AlertCircle,
    Info,
    Bell,
    User,
    Mail,
    Lock,
    Search,
    Settings,
    Trash2,
    Edit,
    Copy,
    Download,
    Share,
    MoreHorizontal,
    ChevronDown,
    Palette,
} from "lucide-vue-next";

const toast = useToast();

// Demo state
const showModal = ref(false);
const showDrawer = ref(false);
const modalSize = ref("md");

// Form demo state
const inputValue = ref("");
const passwordValue = ref("");
const searchValue = ref("");
const checkboxChecked = ref(false);
const checkboxWithDesc = ref(true);
const switchOn = ref(false);
const switchWithLabel = ref(true);
const tags = ref(["Vue", "Laravel", "TypeScript"]);
const selectedStatus = ref("active");
const selectedFilter = ref("");

// Active section for navigation
const activeSection = ref("buttons");

const sections = [
    { id: "buttons", label: "Buttons" },
    { id: "badges", label: "Badges" },
    { id: "avatars", label: "Avatars" },
    { id: "inputs", label: "Inputs" },
    { id: "alerts", label: "Alerts" },
    { id: "cards", label: "Cards" },
    { id: "checkboxes", label: "Checkboxes & Switches" },
    { id: "tooltips", label: "Tooltips" },
    { id: "dropdowns", label: "Dropdowns" },
    { id: "modals", label: "Modals & Drawers" },
    { id: "toasts", label: "Toasts" },
    { id: "misc", label: "Miscellaneous" },
];

// Toast demos
function showSuccessToast() {
    toast.success("Success!", "Your changes have been saved successfully.");
}

function showErrorToast() {
    toast.error("Error", "Something went wrong. Please try again.");
}

function showWarningToast() {
    toast.warning("Warning", "Your session will expire in 5 minutes.");
}

function showInfoToast() {
    toast.info("Info", "A new version is available for download.");
}

function scrollToSection(id) {
    activeSection.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: "smooth" });
}

// Filter options for SelectFilter demo
const filterOptions = [
    { value: "", label: "All Items" },
    { value: "active", label: "Active" },
    { value: "pending", label: "Pending" },
    { value: "completed", label: "Completed" },
];

// Status options for StatusBadge demo
const statusOptions = ["active", "inactive", "pending", "completed", "error"];
</script>

<template>
    <div class="flex gap-6">
        <!-- Sidebar Navigation -->
        <div class="hidden lg:block w-56 shrink-0">
            <div
                class="sticky top-6 space-y-1 p-4 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)]"
            >
                <h3
                    class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-3 px-2"
                >
                    Components
                </h3>
                <button
                    v-for="section in sections"
                    :key="section.id"
                    @click="scrollToSection(section.id)"
                    :class="[
                        'w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                        activeSection === section.id
                            ? 'bg-[var(--interactive-primary)] text-white'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]',
                    ]"
                >
                    {{ section.label }}
                </button>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1 space-y-12 pb-12">
            <!-- Page Header -->
            <div
                class="bg-gradient-to-r from-[var(--color-primary-50)] to-[var(--color-primary-100)] dark:from-[var(--color-primary-900)]/20 dark:to-[var(--color-primary-800)]/20 rounded-2xl p-8 border border-[var(--color-primary-200)] dark:border-[var(--color-primary-800)]"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--interactive-primary)] shadow-lg shadow-[var(--color-primary-500)]/25"
                    >
                        <Palette class="h-7 w-7 text-white" />
                    </div>
                    <div>
                        <h1
                            class="text-3xl font-bold text-[var(--text-primary)]"
                        >
                            Component Gallery
                        </h1>
                        <p class="text-[var(--text-secondary)] mt-1">
                            A comprehensive showcase of all reusable UI
                            components in the application.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Buttons Section -->
            <section id="buttons" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Buttons
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        6 variants, 8 sizes, loading and disabled states.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Variants
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Button variant="primary">Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Danger</Button>
                        <Button variant="link">Link</Button>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Sizes
                    </h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="xs">Extra Small</Button>
                        <Button size="sm">Small</Button>
                        <Button size="md">Medium</Button>
                        <Button size="lg">Large</Button>
                        <Button size="xl">Extra Large</Button>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Icon Buttons
                    </h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button size="icon-xs" variant="secondary">
                            <Settings class="h-3 w-3" />
                        </Button>
                        <Button size="icon-sm" variant="secondary">
                            <Settings class="h-4 w-4" />
                        </Button>
                        <Button size="icon" variant="secondary">
                            <Settings class="h-5 w-5" />
                        </Button>
                        <Button size="icon" variant="primary">
                            <Sparkles class="h-5 w-5" />
                        </Button>
                        <Button size="icon" variant="danger">
                            <Trash2 class="h-5 w-5" />
                        </Button>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        States
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Button :loading="true">Loading...</Button>
                        <Button disabled>Disabled</Button>
                        <Button fullWidth class="max-w-xs">Full Width</Button>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        With Icons
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Button><Download class="h-4 w-4" /> Download</Button>
                        <Button variant="secondary"
                            ><Share class="h-4 w-4" /> Share</Button
                        >
                        <Button variant="outline"
                            ><Edit class="h-4 w-4" /> Edit</Button
                        >
                        <Button variant="danger"
                            ><Trash2 class="h-4 w-4" /> Delete</Button
                        >
                    </div>
                </Card>
            </section>

            <!-- Badges Section -->
            <section id="badges" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Badges
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        9 variants, 4 sizes, optional dot indicator.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Variants
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Badge variant="default">Default</Badge>
                        <Badge variant="primary">Primary</Badge>
                        <Badge variant="secondary">Secondary</Badge>
                        <Badge variant="success">Success</Badge>
                        <Badge variant="warning">Warning</Badge>
                        <Badge variant="error">Error</Badge>
                        <Badge variant="danger">Danger</Badge>
                        <Badge variant="outline">Outline</Badge>
                        <Badge variant="neutral">Neutral</Badge>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Sizes
                    </h3>
                    <div class="flex flex-wrap items-center gap-3">
                        <Badge variant="primary" size="xs">Extra Small</Badge>
                        <Badge variant="primary" size="sm">Small</Badge>
                        <Badge variant="primary" size="md">Medium</Badge>
                        <Badge variant="primary" size="lg">Large</Badge>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        With Dot
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Badge variant="default" dot>Default</Badge>
                        <Badge variant="primary" dot>Primary</Badge>
                        <Badge variant="success" dot>Success</Badge>
                        <Badge variant="warning" dot>Warning</Badge>
                        <Badge variant="error" dot>Error</Badge>
                    </div>
                </Card>
            </section>

            <!-- Avatars Section -->
            <section id="avatars" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Avatars
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        9 sizes, status indicators, ring, image and fallback
                        modes.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Sizes
                    </h3>
                    <div class="flex flex-wrap items-center gap-4">
                        <Avatar fallback="XS" size="xs" />
                        <Avatar fallback="SM" size="sm" />
                        <Avatar fallback="MD" size="md" />
                        <Avatar fallback="LG" size="lg" />
                        <Avatar fallback="XL" size="xl" />
                        <Avatar fallback="2X" size="2xl" />
                        <Avatar fallback="3X" size="3xl" />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Status Indicators
                    </h3>
                    <div class="flex flex-wrap items-center gap-4">
                        <Avatar fallback="JD" size="lg" status="online" />
                        <Avatar fallback="JD" size="lg" status="offline" />
                        <Avatar fallback="JD" size="lg" status="away" />
                        <Avatar fallback="JD" size="lg" status="busy" />
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mt-3">
                        Online • Offline • Away • Busy
                    </p>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        With Ring
                    </h3>
                    <div class="flex flex-wrap items-center gap-4">
                        <Avatar fallback="AB" size="md" ring />
                        <Avatar fallback="CD" size="md" ring />
                        <Avatar fallback="EF" size="md" ring />
                        <Avatar fallback="GH" size="md" ring />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Avatar Stack
                    </h3>
                    <div class="flex -space-x-3">
                        <Avatar fallback="SC" size="md" ring />
                        <Avatar fallback="MJ" size="md" ring />
                        <Avatar fallback="ED" size="md" ring />
                        <Avatar fallback="AT" size="md" ring />
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--surface-tertiary)] border-2 border-[var(--surface-elevated)] text-xs font-medium text-[var(--text-secondary)]"
                        >
                            +5
                        </div>
                    </div>
                </Card>
            </section>

            <!-- Inputs Section -->
            <section id="inputs" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Inputs
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Text input with labels, hints, errors, icons, and
                        password toggle.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Basic Input
                    </h3>
                    <div class="max-w-md space-y-4">
                        <Input
                            v-model="inputValue"
                            label="Username"
                            placeholder="Enter your username"
                        />
                        <Input
                            label="Email"
                            placeholder="you@example.com"
                            :icon="Mail"
                        />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Sizes
                    </h3>
                    <div class="max-w-md space-y-4">
                        <Input placeholder="Small input" size="sm" />
                        <Input placeholder="Medium input (default)" size="md" />
                        <Input placeholder="Large input" size="lg" />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        States
                    </h3>
                    <div class="max-w-md space-y-4">
                        <Input label="With Hint" hint="This is a helpful hint" />
                        <Input
                            label="With Error"
                            error="This field is required"
                        />
                        <Input label="Disabled" disabled placeholder="Can't edit this" />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Password Input
                    </h3>
                    <div class="max-w-md">
                        <Input
                            v-model="passwordValue"
                            type="password"
                            label="Password"
                            placeholder="Enter your password"
                            :icon="Lock"
                        />
                    </div>
                </Card>
            </section>

            <!-- Alerts Section -->
            <section id="alerts" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Alerts
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        5 variants with optional title and dismiss button.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Variants
                    </h3>
                    <div class="space-y-4">
                        <Alert variant="default" title="Default Alert">
                            This is a default informational alert message.
                        </Alert>
                        <Alert variant="success" title="Success!">
                            Your operation completed successfully.
                        </Alert>
                        <Alert variant="error" title="Error">
                            Something went wrong. Please try again.
                        </Alert>
                        <Alert variant="warning" title="Warning">
                            Please review before proceeding.
                        </Alert>
                        <Alert variant="info" title="Information">
                            Here's some important information for you.
                        </Alert>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Without Title
                    </h3>
                    <div class="space-y-4">
                        <Alert variant="info">
                            This is an alert without a title.
                        </Alert>
                        <Alert variant="success">
                            Simple success message.
                        </Alert>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Dismissible
                    </h3>
                    <div class="space-y-4">
                        <Alert variant="info" title="Dismissible Alert" dismissible>
                            Click the X button to dismiss this alert.
                        </Alert>
                    </div>
                </Card>
            </section>

            <!-- Cards Section -->
            <section id="cards" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Cards
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        3 variants, 5 padding options, hover and clickable
                        states.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <Card padding="lg" variant="default">
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            Default Card
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Standard card with elevated background and border.
                        </p>
                    </Card>
                    <Card padding="lg" variant="ghost">
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            Ghost Card
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Subtle background with no border.
                        </p>
                    </Card>
                    <Card padding="lg" variant="outline">
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            Outline Card
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Transparent with border only.
                        </p>
                    </Card>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Padding Options
                    </h3>
                    <div class="flex flex-wrap gap-4">
                        <Card padding="none" class="inline-block">
                            <div class="p-2 text-xs">none</div>
                        </Card>
                        <Card padding="sm" class="inline-block">
                            <div class="text-xs">sm</div>
                        </Card>
                        <Card padding="md" class="inline-block">
                            <div class="text-xs">md</div>
                        </Card>
                        <Card padding="lg" class="inline-block">
                            <div class="text-xs">lg</div>
                        </Card>
                        <Card padding="xl" class="inline-block">
                            <div class="text-xs">xl</div>
                        </Card>
                    </div>
                </Card>

                <div class="grid gap-4 md:grid-cols-2">
                    <Card padding="lg" hover>
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            Hover Card
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Hover over me to see the effect.
                        </p>
                    </Card>
                    <Card padding="lg" clickable>
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-2"
                        >
                            Clickable Card
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            Click me to see the active state.
                        </p>
                    </Card>
                </div>
            </section>

            <!-- Checkboxes & Switches Section -->
            <section id="checkboxes" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Checkboxes & Switches
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Boolean inputs with labels and descriptions.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Checkboxes
                    </h3>
                    <div class="space-y-4">
                        <Checkbox
                            v-model="checkboxChecked"
                            label="Simple checkbox"
                        />
                        <Checkbox
                            v-model="checkboxWithDesc"
                            label="With description"
                            description="This checkbox has additional descriptive text."
                        />
                        <Checkbox label="Disabled checkbox" disabled />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Switches
                    </h3>
                    <div class="space-y-4 max-w-sm">
                        <Switch v-model="switchOn" />
                        <Switch
                            v-model="switchWithLabel"
                            label="Enable notifications"
                            description="Receive email notifications for updates."
                        />
                        <Switch label="Disabled switch" disabled />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Switch Sizes
                    </h3>
                    <div class="flex flex-wrap items-center gap-6">
                        <div class="flex items-center gap-2">
                            <Switch size="sm" :modelValue="true" />
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Small</span
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <Switch size="md" :modelValue="true" />
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Medium</span
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <Switch size="lg" :modelValue="true" />
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Large</span
                            >
                        </div>
                    </div>
                </Card>
            </section>

            <!-- Tooltips Section -->
            <section id="tooltips" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Tooltips
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        4 positions with customizable content.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Positions
                    </h3>
                    <div class="flex flex-wrap gap-4">
                        <Tooltip content="Tooltip on top" side="top">
                            <Button variant="outline">Top</Button>
                        </Tooltip>
                        <Tooltip content="Tooltip on right" side="right">
                            <Button variant="outline">Right</Button>
                        </Tooltip>
                        <Tooltip content="Tooltip on bottom" side="bottom">
                            <Button variant="outline">Bottom</Button>
                        </Tooltip>
                        <Tooltip content="Tooltip on left" side="left">
                            <Button variant="outline">Left</Button>
                        </Tooltip>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Icon Buttons with Tooltips
                    </h3>
                    <div class="flex gap-2">
                        <Tooltip content="Edit">
                            <Button size="icon" variant="secondary">
                                <Edit class="h-4 w-4" />
                            </Button>
                        </Tooltip>
                        <Tooltip content="Copy to clipboard">
                            <Button size="icon" variant="secondary">
                                <Copy class="h-4 w-4" />
                            </Button>
                        </Tooltip>
                        <Tooltip content="Delete">
                            <Button size="icon" variant="secondary">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </Tooltip>
                        <Tooltip content="Share">
                            <Button size="icon" variant="secondary">
                                <Share class="h-4 w-4" />
                            </Button>
                        </Tooltip>
                    </div>
                </Card>
            </section>

            <!-- Dropdowns Section -->
            <section id="dropdowns" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Dropdowns
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Contextual menus with items, labels, and separators.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Basic Dropdown
                    </h3>
                    <div class="flex gap-4">
                        <Dropdown>
                            <template #trigger>
                                <Button variant="outline">
                                    Actions
                                    <ChevronDown class="h-4 w-4" />
                                </Button>
                            </template>
                            <DropdownLabel>Actions</DropdownLabel>
                            <DropdownItem>
                                <Edit class="h-4 w-4" />
                                Edit
                            </DropdownItem>
                            <DropdownItem>
                                <Copy class="h-4 w-4" />
                                Duplicate
                            </DropdownItem>
                            <DropdownSeparator />
                            <DropdownItem>
                                <Download class="h-4 w-4" />
                                Download
                            </DropdownItem>
                            <DropdownItem>
                                <Share class="h-4 w-4" />
                                Share
                            </DropdownItem>
                            <DropdownSeparator />
                            <DropdownItem class="text-red-600 dark:text-red-400">
                                <Trash2 class="h-4 w-4" />
                                Delete
                            </DropdownItem>
                        </Dropdown>

                        <Dropdown align="start">
                            <template #trigger>
                                <Button size="icon" variant="ghost">
                                    <MoreHorizontal class="h-4 w-4" />
                                </Button>
                            </template>
                            <DropdownItem>View Profile</DropdownItem>
                            <DropdownItem>Settings</DropdownItem>
                            <DropdownSeparator />
                            <DropdownItem>Sign Out</DropdownItem>
                        </Dropdown>
                    </div>
                </Card>
            </section>

            <!-- Modals & Drawers Section -->
            <section id="modals" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Modals & Drawers
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Overlay dialogs in various sizes.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Modal Sizes
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            variant="secondary"
                            @click="
                                modalSize = 'sm';
                                showModal = true;
                            "
                        >
                            <Sparkles class="h-4 w-4" />
                            Small Modal
                        </Button>
                        <Button
                            variant="secondary"
                            @click="
                                modalSize = 'md';
                                showModal = true;
                            "
                        >
                            <Sparkles class="h-4 w-4" />
                            Medium Modal
                        </Button>
                        <Button
                            variant="secondary"
                            @click="
                                modalSize = 'lg';
                                showModal = true;
                            "
                        >
                            <Sparkles class="h-4 w-4" />
                            Large Modal
                        </Button>
                        <Button
                            variant="secondary"
                            @click="
                                modalSize = 'xl';
                                showModal = true;
                            "
                        >
                            <Sparkles class="h-4 w-4" />
                            XL Modal
                        </Button>
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Drawer
                    </h3>
                    <Button variant="secondary" @click="showDrawer = true">
                        <PanelRight class="h-4 w-4" />
                        Open Drawer
                    </Button>
                </Card>
            </section>

            <!-- Toasts Section -->
            <section id="toasts" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Toasts
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Notification messages in 4 variants.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Toast Types
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <Button variant="secondary" @click="showSuccessToast">
                            <CheckCircle class="h-4 w-4 text-green-500" />
                            Success Toast
                        </Button>
                        <Button variant="secondary" @click="showErrorToast">
                            <AlertCircle class="h-4 w-4 text-red-500" />
                            Error Toast
                        </Button>
                        <Button variant="secondary" @click="showWarningToast">
                            <Bell class="h-4 w-4 text-yellow-500" />
                            Warning Toast
                        </Button>
                        <Button variant="secondary" @click="showInfoToast">
                            <Info class="h-4 w-4 text-blue-500" />
                            Info Toast
                        </Button>
                    </div>
                </Card>
            </section>

            <!-- Miscellaneous Section -->
            <section id="misc" class="space-y-6">
                <div>
                    <h2
                        class="text-xl font-bold text-[var(--text-primary)] mb-2"
                    >
                        Miscellaneous
                    </h2>
                    <p class="text-[var(--text-secondary)]">
                        Additional utility components.
                    </p>
                </div>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Tag Input
                    </h3>
                    <div class="max-w-md">
                        <TagInput
                            v-model="tags"
                            label="Tags"
                            placeholder="Add a tag..."
                        />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Search Input
                    </h3>
                    <div class="max-w-md">
                        <SearchInput
                            v-model="searchValue"
                            placeholder="Search components..."
                        />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Select Filter
                    </h3>
                    <div class="max-w-xs">
                        <SelectFilter
                            v-model="selectedFilter"
                            :options="filterOptions"
                            placeholder="Filter by status"
                        />
                    </div>
                </Card>

                <Card padding="lg">
                    <h3
                        class="text-sm font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Status Badge
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <StatusBadge status="active" />
                        <StatusBadge status="inactive" />
                        <StatusBadge status="pending" />
                        <StatusBadge status="completed" />
                    </div>
                </Card>
            </section>
        </div>

        <!-- Demo Modal -->
        <Modal
            v-model:open="showModal"
            title="Example Modal"
            description="This is a modal dialog with customizable size."
            :size="modalSize"
        >
            <div class="space-y-4">
                <p class="text-[var(--text-secondary)]">
                    This modal is using the <strong>{{ modalSize }}</strong> size.
                    You can open modals in different sizes: sm, md, lg, xl, or full.
                </p>
                <Input label="Example Input" placeholder="Type something..." />
            </div>

            <template #footer>
                <Button variant="outline" @click="showModal = false"
                    >Cancel</Button
                >
                <Button
                    @click="
                        showModal = false;
                        showSuccessToast();
                    "
                    >Confirm</Button
                >
            </template>
        </Modal>

        <!-- Demo Drawer -->
        <Drawer
            v-model:open="showDrawer"
            title="Example Drawer"
            description="A slide-out panel for detailed content"
            size="md"
        >
            <div class="space-y-6">
                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] mb-3"
                    >
                        Drawer Information
                    </h4>
                    <div class="space-y-3">
                        <div
                            class="flex justify-between py-2 border-b border-[var(--border-muted)]"
                        >
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Status</span
                            >
                            <Badge variant="primary" size="sm">Active</Badge>
                        </div>
                        <div
                            class="flex justify-between py-2 border-b border-[var(--border-muted)]"
                        >
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Progress</span
                            >
                            <span
                                class="text-sm font-semibold text-[var(--text-primary)]"
                                >75%</span
                            >
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Last Updated</span
                            >
                            <span
                                class="text-sm font-semibold text-[var(--text-primary)]"
                                >Just now</span
                            >
                        </div>
                    </div>
                </div>

                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] mb-3"
                    >
                        Team
                    </h4>
                    <div class="flex -space-x-2">
                        <Avatar fallback="SC" size="sm" ring />
                        <Avatar fallback="MJ" size="sm" ring />
                        <Avatar fallback="ED" size="sm" ring />
                        <Avatar fallback="AT" size="sm" ring />
                    </div>
                </div>

                <div>
                    <h4
                        class="text-sm font-semibold text-[var(--text-primary)] mb-3"
                    >
                        Description
                    </h4>
                    <p
                        class="text-sm text-[var(--text-secondary)] leading-relaxed"
                    >
                        This is an example drawer component. Drawers are useful
                        for displaying secondary content or forms without leaving
                        the current page context.
                    </p>
                </div>
            </div>

            <template #footer>
                <Button variant="outline" @click="showDrawer = false"
                    >Close</Button
                >
                <Button
                    @click="
                        showDrawer = false;
                        showSuccessToast();
                    "
                    >Save Changes</Button
                >
            </template>
        </Drawer>
    </div>
</template>
