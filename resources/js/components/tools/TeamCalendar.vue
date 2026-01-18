```vue
<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import listPlugin from "@fullcalendar/list";
import interactionPlugin from "@fullcalendar/interaction";
import multiMonthPlugin from "@fullcalendar/multimonth";
import { Card, Button } from "@/components/ui";
import {
    ChevronLeft,
    ChevronRight,
    Globe,
    ChevronDown,
    Plus,
    Download,
} from "lucide-vue-next";
import { format } from "date-fns";
import api from "@/lib/api";

const props = defineProps({
    events: {
        type: Array,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    "dates-set",
    "create-click",
    "event-click",
    "date-click",
    "export-click",
]);

const calendarRef = ref<any>(null);
const currentTitle = ref("");
const currentView = ref("dayGridMonth");
// _calendarApi removed

// Filters
const showProjects = ref(true);
const showTasks = ref(true);
const showEvents = ref(true);

// Holidays
const showHolidays = ref(false);
const holidays = ref<any[]>([]);
const selectedCountry = ref("US"); // Default
const countries = ref<any[]>([]);
const showCountryDropdown = ref(false);
const popularCountries = [
    "US",
    "GB",
    "DE",
    "FR",
    "CA",
    "AU",
    "JP",
    "IN",
    "BR",
    "PH",
];
const currentRange = ref({ start: null, end: null });

// Fetch Countries on mount
onMounted(() => {
    fetchCountries();
});

async function fetchCountries() {
    try {
        const response = await api.get("/api/holidays/countries");
        countries.value = response.data;
    } catch (error) {
        console.error("Failed to fetch countries:", error);
    }
}

async function fetchHolidays(start, end) {
    if (!showHolidays.value) return;

    try {
        const response = await api.get("/api/holidays", {
            params: {
                country: selectedCountry.value,
                start: format(start, "yyyy-MM-dd"),
                end: format(end, "yyyy-MM-dd"),
            },
        });
        holidays.value = response.data.map((h) => ({
            ...h,
            // Ensure type is 'holiday' for styling
            extendedProps: { type: "holiday", ...h.extendedProps },
        }));
    } catch (error) {
        console.error("Failed to fetch holidays:", error);
    }
}

function toggleHolidays() {
    showHolidays.value = !showHolidays.value;
    if (showHolidays.value && currentRange.value.start) {
        fetchHolidays(currentRange.value.start, currentRange.value.end);
    }
}

function selectCountry(code) {
    selectedCountry.value = code;
    showCountryDropdown.value = false;
    if (showHolidays.value && currentRange.value.start) {
        fetchHolidays(currentRange.value.start, currentRange.value.end);
    }
}

const filteredEvents = computed(() => {
    const teamEvents = props.events.filter((event) => {
        const type = event.extendedProps?.type;
        if (!showProjects.value && type === "project") return false;
        if (!showTasks.value && type === "task") return false;
        if (!showEvents.value && type === "event") return false;
        return true;
    });

    if (showHolidays.value) {
        // Map holidays to FullCalendar event format if needed, but api typically returns usable structure or Index.vue handles it.
        // Index.vue: holidays.value = response.data;
        // And then merges them.
        // We need to map holidays if they are not in FullCalendar format.
        // Index.vue seems to expect them to be reasonably formatted or uses extendedProps type 'holiday'.
        // Let's assume response.data needs mapping or is fine.
        // Based on Calendar/Index.vue, it just spreads `...holidays.value`.
        // Let's map them to be safe or inspect structure.
        // But since I can't run api, I'll trust Index.vue pattern.
        return [...teamEvents, ...holidays.value];
    }
    return teamEvents;
});

const calendarOptions = computed(() => ({
    plugins: [
        dayGridPlugin,
        timeGridPlugin,
        listPlugin,
        interactionPlugin,
        multiMonthPlugin,
    ],
    initialView: currentView.value,
    headerToolbar: false, // Custom header
    events: filteredEvents.value,
    editable: false, // Drag and drop not implemented yet
    selectable: true,
    dayMaxEvents: true,
    height: "auto",

    // Callbacks
    datesSet: handleDatesSet,
    eventClick: (info: any) => emit("event-click", info),
    dateClick: (info: any) => emit("date-click", info),

    // Styling
    eventClassNames: (arg: any) => {
        return [`type-${arg.event.extendedProps.type || "event"}`];
    },
}));

function handleDatesSet(arg: any) {
    currentRange.value = { start: arg.start, end: arg.end };
    currentTitle.value = arg.view.title;

    if (showHolidays.value) {
        fetchHolidays(arg.start, arg.end);
    }

    // Emit start/end range to parent to fetch events
    emit("dates-set", {
        start: arg.start,
        end: arg.end,
    });
}

const changeView = (view: string) => {
    currentView.value = view;
    const api = calendarRef.value?.getApi();
    if (api) api.changeView(view);
};

const prev = () => calendarRef.value?.getApi()?.prev();
const next = () => calendarRef.value?.getApi()?.next();
const today = () => calendarRef.value?.getApi()?.today();
</script>

<template>
    <div class="space-y-4">
        <!-- Toolbar -->
        <Card class="p-4">
            <div
                class="flex flex-col xl:flex-row items-center justify-between gap-4"
            >
                <!-- Navigation & Title -->
                <div
                    class="flex items-center gap-4 w-full xl:w-auto justify-between xl:justify-start"
                >
                    <div class="flex items-center gap-1">
                        <Button variant="ghost" size="sm" @click="prev">
                            <ChevronLeft class="h-4 w-4" />
                        </Button>
                        <Button variant="outline" size="sm" @click="today">
                            Today
                        </Button>
                        <Button variant="ghost" size="sm" @click="next">
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                    </div>
                    <h2
                        class="text-lg font-semibold whitespace-nowrap hidden sm:block"
                    >
                        {{ currentTitle }}
                    </h2>
                </div>

                <!-- Filters & Views & Actions -->
                <div
                    class="flex flex-wrap items-center gap-3 w-full xl:w-auto justify-end"
                >
                    <!-- Holiday Toggle & Country Selector -->
                    <div class="flex items-center gap-2">
                        <button
                            @click="toggleHolidays"
                            class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-all text-sm font-medium"
                            :class="
                                showHolidays
                                    ? 'bg-red-500/10 border-red-500/30 text-red-400 hover:bg-red-500/20'
                                    : 'bg-[var(--surface-elevated)] border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]'
                            "
                        >
                            🎉 Holidays
                        </button>

                        <!-- Country Dropdown -->
                        <div v-if="showHolidays" class="relative">
                            <button
                                @click="
                                    showCountryDropdown = !showCountryDropdown
                                "
                                class="flex items-center gap-2 px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors"
                            >
                                <Globe
                                    class="h-4 w-4 text-[var(--text-tertiary)]"
                                />
                                {{ selectedCountry }}
                                <ChevronDown
                                    class="h-4 w-4 text-[var(--text-tertiary)]"
                                />
                            </button>

                            <div
                                v-if="showCountryDropdown"
                                class="absolute right-0 top-full mt-1 w-64 max-h-80 overflow-y-auto rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-lg z-50"
                            >
                                <!-- Popular Countries -->
                                <div
                                    class="p-2 border-b border-[var(--border-default)]"
                                >
                                    <div
                                        class="text-xs font-medium text-[var(--text-tertiary)] px-2 mb-1"
                                    >
                                        Popular
                                    </div>
                                    <div class="grid grid-cols-5 gap-1">
                                        <button
                                            v-for="code in popularCountries"
                                            :key="code"
                                            @click="selectCountry(code)"
                                            class="px-2 py-1.5 text-xs rounded-md transition-colors"
                                            :class="
                                                selectedCountry === code
                                                    ? 'bg-[var(--interactive-primary)] text-white'
                                                    : 'hover:bg-[var(--surface-secondary)] text-[var(--text-primary)]'
                                            "
                                        >
                                            {{ code }}
                                        </button>
                                    </div>
                                </div>

                                <!-- All Countries -->
                                <div class="p-2">
                                    <div
                                        class="text-xs font-medium text-[var(--text-tertiary)] px-2 mb-1"
                                    >
                                        All Countries
                                    </div>
                                    <div class="space-y-0.5">
                                        <button
                                            v-for="country in countries"
                                            :key="country.countryCode"
                                            @click="
                                                selectCountry(
                                                    country.countryCode
                                                )
                                            "
                                            class="w-full text-left px-3 py-2 text-sm rounded-md transition-colors flex items-center justify-between"
                                            :class="
                                                selectedCountry ===
                                                country.countryCode
                                                    ? 'bg-[var(--interactive-primary)] text-white'
                                                    : 'hover:bg-[var(--surface-secondary)] text-[var(--text-primary)]'
                                            "
                                        >
                                            <span>{{ country.name }}</span>
                                            <span class="text-xs opacity-60">{{
                                                country.countryCode
                                            }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Click outside to close dropdown -->
                        <div
                            v-if="showCountryDropdown"
                            class="fixed inset-0 z-40"
                            @click="showCountryDropdown = false"
                        ></div>
                    </div>

                    <!-- Event Type Filters -->
                    <div class="flex items-center gap-2 mr-2">
                        <label
                            class="flex items-center gap-1.5 text-sm cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                v-model="showProjects"
                                class="rounded border-gray-300 text-blue-500 focus:ring-blue-500"
                            />
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-blue-500"
                            ></span>
                            <span class="hidden sm:inline">Projects</span>
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-sm cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                v-model="showTasks"
                                class="rounded border-gray-300 text-emerald-500 focus:ring-emerald-500"
                            />
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-emerald-500"
                            ></span>
                            <span class="hidden sm:inline">Tasks</span>
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-sm cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                v-model="showEvents"
                                class="rounded border-gray-300 text-purple-500 focus:ring-purple-500"
                            />
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-purple-500"
                            ></span>
                            <span class="hidden sm:inline">Events</span>
                        </label>
                    </div>

                    <div
                        class="h-6 w-px bg-[var(--border-default)] hidden sm:block"
                    ></div>

                    <!-- View Switcher -->
                    <div
                        class="flex items-center bg-[var(--surface-secondary)] rounded-lg p-1"
                    >
                        <button
                            v-for="view in [
                                'dayGridMonth',
                                'timeGridWeek',
                                'timeGridDay',
                                'listMonth',
                            ]"
                            :key="view"
                            @click="changeView(view)"
                            class="px-3 py-1 text-xs font-medium rounded-md transition-all"
                            :class="
                                currentView === view
                                    ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                    : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            "
                        >
                            {{
                                view === "dayGridMonth"
                                    ? "Month"
                                    : view === "timeGridWeek"
                                    ? "Week"
                                    : view === "timeGridDay"
                                    ? "Day"
                                    : "List"
                            }}
                        </button>
                    </div>

                    <Button
                        v-if="canCreate"
                        size="sm"
                        @click="$emit('create-click')"
                    >
                        <Plus class="h-4 w-4 mr-1" />
                        Event
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="$emit('export-click', currentRange)"
                        title="Export events as ICS file"
                    >
                        <Download class="h-4 w-4 mr-1" />
                        Export
                    </Button>
                </div>
            </div>
            <h2
                class="text-lg font-semibold whitespace-nowrap sm:hidden mt-2 text-center"
            >
                {{ currentTitle }}
            </h2>
        </Card>

        <!-- Calendar Area -->
        <Card class="p-0 overflow-hidden min-h-[600px] team-calendar-wrapper">
            <FullCalendar ref="calendarRef" :options="calendarOptions" />
        </Card>
    </div>
</template>

<style>
.team-calendar-wrapper .fc {
    font-family: inherit;
    --fc-border-color: var(--border-default);
    --fc-page-bg-color: transparent;
    --fc-neutral-bg-color: var(--surface-secondary);
    --fc-list-event-hover-bg-color: var(--surface-secondary);
    --fc-today-bg-color: var(--surface-secondary);
}

.team-calendar-wrapper .fc-theme-standard td,
.team-calendar-wrapper .fc-theme-standard th {
    border-color: var(--border-default);
}

.team-calendar-wrapper .fc-col-header-cell {
    padding: 12px 0;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.75rem;
    background-color: var(--surface-secondary);
}

.team-calendar-wrapper .fc-daygrid-day-number {
    font-size: 0.875rem;
    padding: 8px;
    color: var(--text-secondary);
}

.team-calendar-wrapper .fc-day-today {
    background-color: transparent !important;
}
.team-calendar-wrapper .fc-day-today .fc-daygrid-day-number {
    color: var(--interactive-primary);
    font-weight: 700;
}

/* Event Styling */
.team-calendar-wrapper .fc-event {
    border-radius: 4px;
    padding: 2px 4px;
    font-size: 0.75rem;
    cursor: pointer;
    border: none !important;
    transition: opacity 0.2s;
}
.team-calendar-wrapper .fc-event:hover {
    opacity: 0.9;
}

.team-calendar-wrapper .fc-event-title {
    font-weight: 500;
}

/* Fix list view */
.team-calendar-wrapper .fc-list {
    border: none;
}
.team-calendar-wrapper .fc-list-day-cushion {
    background-color: var(--surface-secondary) !important;
}
.team-calendar-wrapper .fc-list-event:hover td {
    background-color: var(--surface-secondary) !important;
}

/* Holiday Events - styled as visible tags */
.team-calendar-wrapper .fc-event.type-holiday {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    padding: 3px 8px !important;
    cursor: pointer;
    border-radius: 4px !important;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
    border: none !important;
    color: white !important;
    box-shadow: 0 1px 3px rgba(220, 38, 38, 0.3);
}

.team-calendar-wrapper .fc-event.type-holiday:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.4);
}

/* Holiday event title text */
.team-calendar-wrapper .fc-event.type-holiday .fc-event-title,
.team-calendar-wrapper .fc-event.type-holiday .fc-event-main {
    color: white !important;
}

/* List view holidays */
.team-calendar-wrapper .fc-list-event.type-holiday {
    background-color: rgba(220, 38, 38, 0.1) !important;
}

.team-calendar-wrapper .fc-list-event.type-holiday .fc-list-event-dot {
    border-color: #dc2626 !important;
}
</style>
