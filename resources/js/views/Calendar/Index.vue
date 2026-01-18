<script setup>
import { ref, computed, onMounted } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import listPlugin from "@fullcalendar/list";
import interactionPlugin from "@fullcalendar/interaction";
import multiMonthPlugin from "@fullcalendar/multimonth";
import { format } from "date-fns";
import {
    Plus,
    Globe,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Calendar as CalendarIcon,
    CheckSquare,
    Download,
    Share,
    User,
    SlidersHorizontal,
} from "lucide-vue-next";
import api from "@/lib/api";
import Button from "@/components/ui/Button.vue";
import EventModal from "./Partials/EventModal.vue";
import EventViewModal from "./Partials/EventViewModal.vue";
import EventSummaryTooltip from "./Partials/EventSummaryTooltip.vue";
import CalendarShareModal from "./Partials/CalendarShareModal.vue";
import GoogleCalendarConnect from "@/components/GoogleCalendarConnect.vue";
import { toast } from "vue-sonner";

const calendarRef = ref(null);
const events = ref([]);
const holidays = ref([]);
const isLoading = ref(false);

// User & Sharing State
const currentUser = ref(null);
const sharedCalendars = ref([]);
const selectedCalendarIds = ref([]);
const showCalendarDropdown = ref(false);

// Modal States
const showEventModal = ref(false);
const showViewModal = ref(false);
const showShareModal = ref(false); // New Share Modal State
const selectedEvent = ref(null);
const selectedDate = ref(new Date());

// Selection & Export State
const isSelectionMode = ref(false);
const selectedExportEvents = ref([]);

// Tooltip State
const hoveredEvent = ref(null);
const tooltipPosition = ref({ x: 0, y: 0 });

// Google Events Toggle
const showGoogleEvents = ref(true);

// Holiday settings
const showHolidays = ref(true);
const selectedCountry = ref("US");
const countries = ref([]);
const showCountryDropdown = ref(false);

// Popular countries for quick access
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

// Month/Year navigation
const currentMonth = ref(new Date().getMonth());
const currentYear = ref(new Date().getFullYear());
const currentView = ref("dayGridMonth");
const showMonthDropdown = ref(false);
const showYearDropdown = ref(false);
const showViewOptionsDropdown = ref(false);

const months = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
];

// Generate year range (10 years back, 5 years forward)
const years = computed(() => {
    const current = new Date().getFullYear();
    const range = [];
    for (let i = current - 10; i <= current + 5; i++) {
        range.push(i);
    }
    return range;
});

function toggleSelectionMode() {
    isSelectionMode.value = !isSelectionMode.value;
    selectedExportEvents.value = []; // Clear selection when toggling
}

async function handleBulkExport() {
    if (selectedExportEvents.value.length === 0) return;

    try {
        const response = await api.post(
            "/api/calendar/export/bulk",
            {
                event_ids: selectedExportEvents.value,
            },
            { responseType: "blob" }
        );

        // Create link to download
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.setAttribute(
            "download",
            `calendar-export-${new Date().toISOString().split("T")[0]}.ics`
        );
        document.body.appendChild(link);
        link.click();
        link.remove();

        toast.success(`Exported ${selectedExportEvents.value.length} events`);
        toggleSelectionMode(); // Exit mode after export
    } catch (error) {
        console.error("Export failed:", error);
        toast.error("Failed to export events");
    }
}

function openShareModal() {
    showShareModal.value = true;
}

const isTransitioning = ref(false);

function triggerTransition() {
    isTransitioning.value = true;
    setTimeout(() => {
        isTransitioning.value = false;
    }, 300); // Match CSS transition duration
}

function closeDropdowns() {
    showCountryDropdown.value = false;
    showMonthDropdown.value = false;
    showYearDropdown.value = false;
    showCalendarDropdown.value = false;
    showViewOptionsDropdown.value = false;
}

function changeView(view) {
    if (currentView.value === view) return;
    triggerTransition();
    // Small delay to allow fade out to start
    setTimeout(() => {
        currentView.value = view;
        calendarRef.value?.getApi()?.changeView(view);
    }, 50);
}

function formatViewName(view) {
    const names = {
        dayGridMonth: "Month",
        timeGridWeek: "Week",
        timeGridDay: "Day",
        listWeek: "List",
        multiMonthYear: "Year",
    };
    return names[view] || view;
}

function navigateToMonth(month) {
    if (currentMonth.value === month) {
        showMonthDropdown.value = false;
        return;
    }
    triggerTransition();
    setTimeout(() => {
        currentMonth.value = month;
        showMonthDropdown.value = false;
        goToDate(currentYear.value, month);
    }, 50);
}

function navigateToYear(year) {
    if (currentYear.value === year) {
        showYearDropdown.value = false;
        return;
    }
    triggerTransition();
    setTimeout(() => {
        currentYear.value = year;
        showYearDropdown.value = false;
        goToDate(year, currentMonth.value);
    }, 50);
}

function goToDate(year, month) {
    const calendarApi = calendarRef.value?.getApi();
    if (calendarApi) {
        calendarApi.gotoDate(new Date(year, month, 1));
    }
}

function goToToday() {
    triggerTransition();
    setTimeout(() => {
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            calendarApi.today();
            const now = new Date();
            currentMonth.value = now.getMonth();
            currentYear.value = now.getFullYear();
        }
    }, 50);
}

function navigatePrev() {
    triggerTransition();
    setTimeout(() => {
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            calendarApi.prev();
            updateCurrentMonthYear();
        }
    }, 50);
}

function navigateNext() {
    triggerTransition();
    setTimeout(() => {
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            calendarApi.next();
            updateCurrentMonthYear();
        }
    }, 50);
}

function updateCurrentMonthYear() {
    const calendarApi = calendarRef.value?.getApi();
    if (calendarApi) {
        const date = calendarApi.getDate();
        currentMonth.value = date.getMonth();
        currentYear.value = date.getFullYear();
    }
}

// Combined events (user events + holidays)
const allEvents = computed(() => {
    // If we're in selection mode, we might want to force re-render,
    // but extendedProps reactivity should handle class updates via getEventClassNames
    const userEvents = events.value.map((event) => ({
        id: event.id,
        title: event.title,
        start: event.start_time,
        end: event.end_time,
        allDay: event.is_all_day,
        extendedProps: {
            type: "event",
            description: event.description,
            location: event.location,
            reminder_minutes_before: event.reminder_minutes_before,
            attendees: event.attendees || [],
            attendees: event.attendees || [],
            is_google_event: event.is_google_event, // Pass this through
            rawEvent: event, // Store raw event to pass easily to modals/tooltips
        },
    }));

    // Filter based on showGoogleEvents
    const filteredUserEvents = userEvents.filter((e) => {
        // If it's a google event (checked via is_google_event prop), respect the toggle
        // Note: We need to make sure is_google_event is passed into extendedProps or available
        return showGoogleEvents.value || !e.extendedProps.is_google_event;
    });

    if (!showHolidays.value) {
        return filteredUserEvents;
    }

    return [...filteredUserEvents, ...holidays.value];
});

function getEventClassNames(event) {
    const classes = [];
    if (event.extendedProps?.type === "holiday") {
        classes.push("holiday-event");
    } else {
        classes.push("user-event", "cursor-pointer");
    }

    // Check if selected for export (ensure ID comparison is safe)
    if (
        isSelectionMode.value &&
        selectedExportEvents.value.includes(event.id)
    ) {
        classes.push("selected-for-export");
    }

    return classes;
}

// FullCalendar options
const calendarOptions = computed(() => ({
    plugins: [
        dayGridPlugin,
        timeGridPlugin,
        listPlugin,
        interactionPlugin,
        multiMonthPlugin,
    ],
    initialView: "dayGridMonth",
    headerToolbar: false, // We'll use custom header
    buttonText: {
        today: "Today",
        year: "Year",
        month: "Month",
        week: "Week",
        day: "Day",
        list: "List",
    },
    events: allEvents.value,
    editable: true,
    selectable: true,
    selectMirror: true,
    dayMaxEvents: true,
    weekends: true,
    nowIndicator: true,
    eventDisplay: "auto",
    height: "auto",
    contentHeight: 750,

    // Event handlers
    select: handleDateSelect,
    eventClick: handleEventClick,
    eventDrop: handleEventDrop,
    eventResize: handleEventResize,
    datesSet: handleDatesSet,

    // Hover Handlers
    eventMouseEnter: handleEventMouseEnter,
    eventMouseLeave: handleEventMouseLeave,

    // Styling
    eventClassNames: (arg) => getEventClassNames(arg.event),
    dayCellClassNames:
        "hover:bg-[var(--surface-secondary)]/30 transition-colors",
}));

async function fetchEvents(start, end) {
    isLoading.value = true;
    try {
        const response = await api.get("/api/calendar/events", {
            params: {
                start: format(start, "yyyy-MM-dd HH:mm:ss"),
                end: format(end, "yyyy-MM-dd HH:mm:ss"),
                users: selectedCalendarIds.value,
            },
        });
        // API returns { data: [...] } from ResourceCollection
        const eventsData = response.data.data || response.data;
        events.value = eventsData.map((event) => ({
            ...event,
            start_time: new Date(event.start_time),
            end_time: new Date(event.end_time),
        }));
    } catch (error) {
        console.error("Failed to fetch events:", error);
        toast.error("Failed to load events");
    } finally {
        isLoading.value = false;
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
            extendedProps: { type: "holiday", ...h.extendedProps },
        }));
    } catch (error) {
        console.error("Failed to fetch holidays:", error);
        // Don't show error toast for holidays - fail silently
    }
}

async function fetchCountries() {
    try {
        const response = await api.get("/api/holidays/countries");
        countries.value = response.data;
    } catch (error) {
        console.error("Failed to fetch countries:", error);
    }
}

function handleDatesSet(dateInfo) {
    fetchEvents(dateInfo.start, dateInfo.end);
    fetchHolidays(dateInfo.start, dateInfo.end);
}

function handleDateSelect(selectInfo) {
    selectedEvent.value = null;
    selectedDate.value = selectInfo.start;
    showViewModal.value = false; // Ensure View modal is closed
    showEventModal.value = true;

    const calendarApi = selectInfo.view.calendar;
    calendarApi.unselect();
}

function handleEventClick(clickInfo) {
    // Don't open modal for holidays
    if (clickInfo.event.extendedProps?.type === "holiday") {
        toast.info(`🎉 ${clickInfo.event.title}`, {
            description:
                clickInfo.event.extendedProps.localName !==
                clickInfo.event.title
                    ? clickInfo.event.extendedProps.localName
                    : undefined,
        });
        return;
    }

    // Use extendedProps.rawEvent if available (from allEvents computed), or fallback to matching ID
    const event =
        clickInfo.event.extendedProps?.rawEvent ||
        events.value.find((e) => e.id == clickInfo.event.id);

    if (event) {
        if (isSelectionMode.value) {
            // Toggle selection
            const index = selectedExportEvents.value.indexOf(event.id);
            if (index > -1) {
                selectedExportEvents.value.splice(index, 1);
            } else {
                selectedExportEvents.value.push(event.id);
            }
            return;
        }

        selectedEvent.value = event;
        selectedDate.value = null;
        showEventModal.value = false; // Ensure Edit/Create modal is closed
        showViewModal.value = true; // Open View Modal instead of Edit
    }
}

function handleEventMouseEnter(info) {
    if (info.event.extendedProps?.type === "holiday") return;

    const event =
        info.event.extendedProps?.rawEvent ||
        events.value.find((e) => e.id == info.event.id);
    if (event) {
        hoveredEvent.value = event;
        tooltipPosition.value = {
            x: info.jsEvent.clientX,
            y: info.jsEvent.clientY,
        };
    }
}

function handleEventMouseLeave() {
    hoveredEvent.value = null;
}

async function handleEventDrop(dropInfo) {
    // Can't drag holidays
    if (dropInfo.event.extendedProps?.type === "holiday") {
        dropInfo.revert();
        return;
    }

    const event = events.value.find((e) => e.id == dropInfo.event.id);
    if (!event) return;

    try {
        await api.put(`/api/calendar/events/${event.id}`, {
            ...event,
            start_time: format(dropInfo.event.start, "yyyy-MM-dd HH:mm:ss"),
            end_time: format(
                dropInfo.event.end || dropInfo.event.start,
                "yyyy-MM-dd HH:mm:ss"
            ),
        });
        toast.success("Event moved");
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            fetchEvents(
                calendarApi.view.activeStart,
                calendarApi.view.activeEnd
            );
        }
    } catch (error) {
        console.error("Failed to move event:", error);
        toast.error("Failed to move event");
        dropInfo.revert();
    }
}

async function handleEventResize(resizeInfo) {
    if (resizeInfo.event.extendedProps?.type === "holiday") {
        resizeInfo.revert();
        return;
    }

    const event = events.value.find((e) => e.id == resizeInfo.event.id);
    if (!event) return;

    try {
        await api.put(`/api/calendar/events/${event.id}`, {
            ...event,
            start_time: format(resizeInfo.event.start, "yyyy-MM-dd HH:mm:ss"),
            end_time: format(resizeInfo.event.end, "yyyy-MM-dd HH:mm:ss"),
        });
        toast.success("Event updated");
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            fetchEvents(
                calendarApi.view.activeStart,
                calendarApi.view.activeEnd
            );
        }
    } catch (error) {
        console.error("Failed to resize event:", error);
        toast.error("Failed to update event");
        resizeInfo.revert();
    }
}

function handleNewEvent() {
    selectedEvent.value = null;
    selectedDate.value = new Date();
    showEventModal.value = true;
}

function handleEditEvent(event) {
    selectedEvent.value = event;
    showViewModal.value = false;
    showEventModal.value = true;
}

async function handleDeleteEvent(event) {
    if (!event) return;
    if (
        !confirm(
            "Are you sure you want to delete this event? This action cannot be undone."
        )
    )
        return;

    isLoading.value = true;
    try {
        await api.delete(`/api/calendar/events/${event.id}`);
        toast.success("Event deleted");

        // Refresh events
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            fetchEvents(
                calendarApi.view.activeStart,
                calendarApi.view.activeEnd
            );
        }
    } catch (error) {
        console.error("Failed to delete event:", error);
        toast.error("Failed to delete event");
    } finally {
        isLoading.value = false;
        showViewModal.value = false;
        showEventModal.value = false;
    }
}

function onEventSaved() {
    showEventModal.value = false;
    selectedEvent.value = null;
    const calendarApi = calendarRef.value?.getApi();
    if (calendarApi) {
        fetchEvents(calendarApi.view.activeStart, calendarApi.view.activeEnd);
    }
}

function toggleHolidays() {
    showHolidays.value = !showHolidays.value;
    if (showHolidays.value) {
        const calendarApi = calendarRef.value?.getApi();
        if (calendarApi) {
            fetchHolidays(
                calendarApi.view.activeStart,
                calendarApi.view.activeEnd
            );
        }
    }
}

function selectCountry(code) {
    selectedCountry.value = code;
    showCountryDropdown.value = false;
    const calendarApi = calendarRef.value?.getApi();
    if (calendarApi) {
        fetchHolidays(calendarApi.view.activeStart, calendarApi.view.activeEnd);
    }
}

async function fetchCurrentUser() {
    try {
        const response = await api.get("/api/user");
        const userData = response.data.data || response.data;
        currentUser.value = userData;
        // Select 'My Calendar' by default if selection is empty and we just loaded
        if (selectedCalendarIds.value.length === 0 && userData.public_id) {
            selectedCalendarIds.value.push(userData.public_id);

            // Trigger refresh as we now have a selection
            const calendarApi = calendarRef.value?.getApi();
            if (calendarApi) {
                fetchEvents(
                    calendarApi.view.activeStart,
                    calendarApi.view.activeEnd
                );
            }
        }
    } catch (error) {
        console.error("Failed to fetch user:", error);
    }
}

async function fetchSharedCalendars() {
    try {
        const { data } = await api.get("/api/calendar/shares");
        sharedCalendars.value = data.shared_with_me;
    } catch (error) {
        console.error("Failed to fetch shared calendars:", error);
    }
}

function toggleCalendarSelection(userId) {
    const index = selectedCalendarIds.value.indexOf(userId);
    if (index === -1) {
        selectedCalendarIds.value.push(userId);
    } else {
        selectedCalendarIds.value.splice(index, 1);
    }
    // Refetch events for current view
    const calendarApi = calendarRef.value?.getApi();
    if (calendarApi) {
        fetchEvents(calendarApi.view.activeStart, calendarApi.view.activeEnd);
    }
}

function getCountryName(code) {
    const country = countries.value.find((c) => c.countryCode === code);
    return country?.name || code;
}

onMounted(async () => {
    await fetchCurrentUser();
    fetchSharedCalendars();
    fetchCountries();
});
</script>

<template>
    <div class="h-[calc(100vh-2rem)] flex flex-col gap-6">
        <!-- Premium Header -->
        <div
            class="flex flex-col xl:flex-row xl:items-center justify-between gap-6"
        >
            <div class="space-y-1">
                <h1
                    class="text-3xl font-bold tracking-tight text-[var(--text-primary)]"
                >
                    Calendar
                </h1>
                <p class="text-[var(--text-secondary)] font-medium">
                    Manage your schedule and events efficiently.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <!-- Calendar Selector -->
                <div v-if="currentUser" class="relative">
                    <button
                        @click="showCalendarDropdown = !showCalendarDropdown"
                        class="flex items-center gap-2 px-3 py-2 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors shadow-sm"
                    >
                        <User class="h-4 w-4 text-[var(--text-tertiary)]" />
                        <span class="hidden sm:inline">Calendars</span>
                        <span
                            class="bg-[var(--surface-secondary)] px-1.5 py-0.5 rounded-md text-xs font-semibold text-[var(--text-secondary)]"
                        >
                            {{ selectedCalendarIds.length }}
                        </span>
                        <ChevronDown
                            class="h-3 w-3 text-[var(--text-tertiary)] opacity-50"
                        />
                    </button>

                    <div
                        v-if="showCalendarDropdown"
                        class="absolute left-0 mt-2 w-64 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-xl z-50 overflow-hidden animate-in fade-in zoom-in-95 p-1"
                    >
                        <!-- My Calendar -->
                        <div v-if="currentUser" class="mb-1">
                            <button
                                @click="
                                    toggleCalendarSelection(
                                        currentUser.public_id
                                    )
                                "
                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-colors"
                                :class="
                                    selectedCalendarIds.includes(
                                        currentUser.public_id
                                    )
                                        ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] font-medium'
                                        : 'hover:bg-[var(--surface-secondary)] text-[var(--text-primary)]'
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <User
                                        class="h-4 w-4"
                                        :class="
                                            selectedCalendarIds.includes(
                                                currentUser.public_id
                                            )
                                                ? 'text-[var(--interactive-primary)]'
                                                : 'text-[var(--text-tertiary)]'
                                        "
                                    />
                                    <span>My Calendar</span>
                                </div>
                                <div
                                    v-if="
                                        selectedCalendarIds.includes(
                                            currentUser.public_id
                                        )
                                    "
                                    class="h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
                                ></div>
                            </button>
                        </div>

                        <div class="h-px bg-[var(--border-default)] my-1"></div>

                        <!-- Shared Calendars -->
                        <div class="space-y-0.5 max-h-64 overflow-y-auto">
                            <div
                                class="text-[10px] uppercase tracking-wider font-semibold text-[var(--text-tertiary)] px-2 py-1"
                            >
                                Shared with me
                            </div>
                            <button
                                v-for="user in sharedCalendars"
                                :key="user.id"
                                @click="toggleCalendarSelection(user.public_id)"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-colors"
                                :class="
                                    selectedCalendarIds.includes(user.public_id)
                                        ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] font-medium'
                                        : 'hover:bg-[var(--surface-secondary)] text-[var(--text-primary)]'
                                "
                            >
                                <span>{{ user.name }}</span>
                                <div
                                    v-if="
                                        selectedCalendarIds.includes(
                                            user.public_id
                                        )
                                    "
                                    class="h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
                                ></div>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- View Options Dropdown -->
                <div class="relative">
                    <button
                        @click="
                            showViewOptionsDropdown = !showViewOptionsDropdown
                        "
                        class="flex items-center gap-2 px-3 py-2 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:bg-[var(--surface-secondary)] text-[var(--text-primary)] transition-all shadow-sm text-sm font-medium"
                    >
                        <SlidersHorizontal
                            class="h-4 w-4 text-[var(--text-secondary)]"
                        />
                        <span class="hidden sm:inline">View Options</span>
                        <ChevronDown
                            class="h-3 w-3 text-[var(--text-tertiary)] opacity-50"
                        />
                    </button>

                    <div
                        v-if="showViewOptionsDropdown"
                        class="absolute right-0 top-full mt-2 w-72 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-xl z-50 overflow-hidden animate-in fade-in zoom-in-95 p-2 space-y-2"
                    >
                        <div
                            class="px-2 py-1.5 text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider"
                        >
                            Display Settings
                        </div>

                        <!-- Google Events Toggle -->
                        <button
                            @click="showGoogleEvents = !showGoogleEvents"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg transition-colors"
                            :class="
                                showGoogleEvents
                                    ? 'bg-[var(--surface-secondary)]/50'
                                    : 'hover:bg-[var(--surface-secondary)]'
                            "
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="p-1.5 rounded-md bg-white border border-gray-200 shadow-sm"
                                >
                                    <img
                                        src="https://www.svgrepo.com/show/475656/google-color.svg"
                                        class="h-4 w-4"
                                        :class="{
                                            'grayscale opacity-50':
                                                !showGoogleEvents,
                                        }"
                                        alt="Google"
                                    />
                                </div>
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Google Events</span
                                >
                            </div>
                            <div
                                class="w-9 h-5 rounded-full transition-colors relative"
                                :class="
                                    showGoogleEvents
                                        ? 'bg-blue-500'
                                        : 'bg-gray-300 dark:bg-gray-600'
                                "
                            >
                                <div
                                    class="absolute top-1 left-1 bg-white w-3 h-3 rounded-full transition-transform shadow-sm"
                                    :class="
                                        showGoogleEvents
                                            ? 'translate-x-4'
                                            : 'translate-x-0'
                                    "
                                ></div>
                            </div>
                        </button>

                        <!-- Holidays Toggle -->
                        <button
                            @click="toggleHolidays"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg transition-colors"
                            :class="
                                showHolidays
                                    ? 'bg-[var(--surface-secondary)]/50'
                                    : 'hover:bg-[var(--surface-secondary)]'
                            "
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="p-1.5 rounded-md bg-red-50 border border-red-100/50"
                                >
                                    <span class="text-sm">🎉</span>
                                </div>
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Holidays</span
                                >
                            </div>
                            <div
                                class="w-9 h-5 rounded-full transition-colors relative"
                                :class="
                                    showHolidays
                                        ? 'bg-blue-500'
                                        : 'bg-gray-300 dark:bg-gray-600'
                                "
                            >
                                <div
                                    class="absolute top-1 left-1 bg-white w-3 h-3 rounded-full transition-transform shadow-sm"
                                    :class="
                                        showHolidays
                                            ? 'translate-x-4'
                                            : 'translate-x-0'
                                    "
                                ></div>
                            </div>
                        </button>

                        <!-- Country Selector (Only visible if Holidays enabled) -->
                        <div
                            v-if="showHolidays"
                            class="pt-2 border-t border-[var(--border-default)]"
                        >
                            <div
                                class="px-2 py-1.5 text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider mb-1"
                            >
                                Holiday Region
                            </div>
                            <div class="grid grid-cols-5 gap-2 px-1">
                                <button
                                    v-for="code in popularCountries"
                                    :key="code"
                                    @click="selectCountry(code)"
                                    class="flex items-center justify-center p-2 rounded-lg text-xs font-medium transition-colors border"
                                    :class="
                                        selectedCountry === code
                                            ? 'bg-[var(--interactive-primary)] text-white border-transparent shadow-sm'
                                            : 'bg-[var(--surface-elevated)] border-[var(--border-default)] text-[var(--text-secondary)] hover:border-[var(--border-strong)] hover:text-[var(--text-primary)]'
                                    "
                                >
                                    {{ code }}
                                </button>
                            </div>
                            <button
                                @click="
                                    showCountryDropdown = !showCountryDropdown;
                                    showViewOptionsDropdown = false;
                                "
                                class="w-full mt-2 flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium bg-[var(--surface-secondary)]/50 hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                            >
                                <span>More Regions...</span>
                                <ChevronRight class="h-3 w-3" />
                            </button>
                        </div>
                    </div>

                    <!-- All Countries Dropdown (Replaces View Options when active) -->
                    <div
                        v-if="showCountryDropdown"
                        class="absolute right-0 top-full mt-2 w-72 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-xl z-50 overflow-hidden animate-in fade-in zoom-in-95 duration-200"
                    >
                        <div
                            class="p-2 border-b border-[var(--border-default)] flex items-center justify-between bg-[var(--surface-secondary)]/30"
                        >
                            <span
                                class="text-xs font-semibold uppercase tracking-wider text-[var(--text-tertiary)]"
                                >Select Region</span
                            >
                            <button
                                @click="
                                    showCountryDropdown = false;
                                    showViewOptionsDropdown = true;
                                "
                                class="p-1 hover:bg-[var(--surface-secondary)] rounded-md transition-colors"
                            >
                                <ChevronLeft
                                    class="w-4 h-4 text-[var(--text-secondary)]"
                                />
                            </button>
                        </div>
                        <div
                            class="max-h-80 overflow-y-auto p-2 custom-scrollbar"
                        >
                            <div class="space-y-0.5">
                                <button
                                    v-for="country in countries"
                                    :key="country.countryCode"
                                    @click="selectCountry(country.countryCode)"
                                    class="w-full text-left px-3 py-2 text-sm rounded-lg transition-colors flex items-center justify-between group"
                                    :class="
                                        selectedCountry === country.countryCode
                                            ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] font-medium'
                                            : 'hover:bg-[var(--surface-secondary)] text-[var(--text-primary)]'
                                    "
                                >
                                    <span>{{ country.name }}</span>
                                    <span
                                        class="text-xs text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]"
                                        >{{ country.countryCode }}</span
                                    >
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export / Selection Mode Toggle -->
                <div
                    class="flex items-center bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-sm p-1"
                >
                    <button
                        @click="toggleSelectionMode"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all"
                        :class="
                            isSelectionMode
                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'
                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]'
                        "
                    >
                        <CheckSquare class="w-4 h-4" />
                        <span v-if="!isSelectionMode">Select & Export</span>
                        <span v-else>Done Selecting</span>
                    </button>

                    <div
                        v-if="isSelectionMode"
                        class="h-4 w-px bg-[var(--border-default)] mx-1"
                    ></div>

                    <button
                        v-if="isSelectionMode"
                        @click="handleBulkExport"
                        :disabled="selectedExportEvents.length === 0"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="
                            selectedExportEvents.length > 0
                                ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                                : 'bg-[var(--surface-secondary)] text-[var(--text-muted)]'
                        "
                    >
                        <Download class="w-4 h-4" />
                        Export ({{ selectedExportEvents.length }})
                    </button>
                </div>
                    <!-- END -->





                <!-- Hidden nested country dropdown to preserve existing logic if needed, but we integrated it above -->
                <!-- We can keep the showCountryDropdown logic for the 'More Regions' nested flow or modal if we wanted, 
                     but for now the quick select covers most cases. Let's keep the logic simple. 
                     The original country dropdown was complex. Let's re-use the full country list logic if requested via the 'More Regions' button 
                     by toggling the existing variable, but showing it in context might be tricky. 
                     Actually, let's just make the country selection simple in the dropdown. 
                -->

                <!-- Share Calendar -->
                <Button
                    variant="outline"
                    @click="openShareModal"
                    class="h-[42px] px-4 bg-[var(--surface-elevated)] border-[var(--border-default)] hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                >
                    <Share class="h-4 w-4 mr-2" />
                    Share
                </Button>

                <!-- Google Connect -->
                <GoogleCalendarConnect />

                <!-- Primary Action -->
                <Button
                    @click="handleNewEvent"
                    class="h-[42px] px-6 shadow-lg shadow-blue-500/20 hover:shadow-blue-500/30 transition-all"
                >
                    <Plus class="h-4 w-4 mr-2" />
                    New Event
                </Button>
            </div>
        </div>

        <!-- Click outside to close dropdowns -->
        <!-- Click outside to close dropdowns -->
        <div
            v-if="
                showCountryDropdown ||
                showMonthDropdown ||
                showYearDropdown ||
                showViewOptionsDropdown
            "
            class="fixed inset-0 z-40"
            @click="closeDropdowns"
        ></div>

        <!-- Calendar Container -->
        <div
            class="flex-1 bg-[var(--surface-elevated)] rounded-2xl border border-[var(--border-default)] shadow-sm overflow-hidden flex flex-col fullcalendar-wrapper transition-all duration-300 ease-in-out"
            :class="{
                'selection-mode': isSelectionMode,
                'is-transitioning': isTransitioning,
            }"
        >
            <!-- Custom Toolbar -->
            <div
                class="p-4 border-b border-[var(--border-default)] flex flex-col sm:flex-row items-center justify-between gap-4 bg-[var(--surface-primary)]"
            >
                <!-- Navigation -->
                <div
                    class="flex items-center bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-1 shadow-sm"
                >
                    <button
                        @click="navigatePrev"
                        class="p-2 rounded-lg hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </button>
                    <button
                        @click="goToToday"
                        class="px-3 py-1.5 text-sm font-medium hover:bg-[var(--surface-secondary)] rounded-md transition-colors"
                    >
                        Today
                    </button>
                    <button
                        @click="navigateNext"
                        class="p-2 rounded-lg hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>

                <!-- Date Selectors -->
                <div class="flex items-center gap-3">
                    <!-- Month -->
                    <div class="relative">
                        <button
                            @click="showMonthDropdown = !showMonthDropdown"
                            class="flex items-center gap-2 text-xl font-bold text-[var(--text-primary)] hover:opacity-75 transition-opacity"
                        >
                            {{ months[currentMonth] }}
                            <ChevronDown
                                class="h-5 w-5 text-[var(--text-tertiary)]"
                            />
                        </button>
                        <div
                            v-if="showMonthDropdown"
                            class="absolute top-full left-1/2 -translate-x-1/2 mt-2 w-48 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-xl z-50 p-2 grid grid-cols-1 overflow-hidden animate-in fade-in zoom-in-95"
                        >
                            <button
                                v-for="(month, index) in months"
                                :key="index"
                                @click="navigateToMonth(index)"
                                class="px-3 py-2 rounded-lg text-sm text-center font-medium transition-colors"
                                :class="
                                    currentMonth === index
                                        ? 'bg-[var(--interactive-primary)] text-white'
                                        : 'hover:bg-[var(--surface-secondary)]'
                                "
                            >
                                {{ month }}
                            </button>
                        </div>
                    </div>

                    <!-- Year -->
                    <span
                        class="text-xl font-medium text-[var(--text-tertiary)]"
                        >/</span
                    >
                    <div class="relative">
                        <button
                            @click="showYearDropdown = !showYearDropdown"
                            class="text-xl font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors flex items-center gap-1"
                        >
                            {{ currentYear }}
                            <ChevronDown class="h-4 w-4 opacity-50" />
                        </button>
                        <div
                            v-if="showYearDropdown"
                            class="absolute top-full left-1/2 -translate-x-1/2 mt-2 w-32 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] shadow-xl z-50 p-2 max-h-64 overflow-y-auto custom-scrollbar"
                        >
                            <button
                                v-for="year in years"
                                :key="year"
                                @click="navigateToYear(year)"
                                class="w-full px-3 py-2 rounded-lg text-sm text-center font-medium transition-colors block"
                                :class="
                                    currentYear === year
                                        ? 'bg-[var(--interactive-primary)] text-white'
                                        : 'hover:bg-[var(--surface-secondary)]'
                                "
                            >
                                {{ year }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- View Switcher -->
                <div
                    class="flex bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-1 shadow-sm"
                >
                    <template
                        v-for="view in [
                            'multiMonthYear',
                            'dayGridMonth',
                            'timeGridWeek',
                            'timeGridDay',
                            'listWeek',
                        ]"
                        :key="view"
                    >
                        <button
                            @click="changeView(view)"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all"
                            :class="
                                currentView === view
                                    ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                                    : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]'
                            "
                        >
                            {{ formatViewName(view) }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- Calendar Body -->
            <div class="flex-1 min-h-0 bg-[var(--surface-primary)]">
                <FullCalendar
                    ref="calendarRef"
                    :options="calendarOptions"
                    class="h-full"
                />
            </div>
        </div>

        <!-- Event Tooltip -->
        <EventSummaryTooltip
            v-if="hoveredEvent && !isSelectionMode"
            :event="hoveredEvent"
            :position="tooltipPosition"
        />

        <!-- Edit/New Modal -->
        <EventModal
            :open="showEventModal"
            :event="selectedEvent"
            :selected-date="selectedDate"
            @update:open="showEventModal = $event"
            @saved="onEventSaved"
            @deleted="handleDeleteEvent"
        />

        <!-- View Only Modal -->
        <EventViewModal
            :open="showViewModal"
            :event="selectedEvent"
            @update:open="showViewModal = $event"
            @edit="handleEditEvent"
            @delete="handleDeleteEvent"
        />

        <!-- Share Modal -->
        <CalendarShareModal
            :isOpen="showShareModal"
            :current-user-id="currentUser?.id"
            @close="showShareModal = false"
        />
    </div>
</template>

<style>
/* FullCalendar Theme Customization */
.fullcalendar-wrapper {
    --fc-border-color: var(--border-default);
    --fc-button-bg-color: var(--surface-elevated);
    --fc-button-border-color: var(--border-default);
    --fc-button-text-color: var(--text-primary);
    --fc-button-hover-bg-color: var(--surface-secondary);
    --fc-button-hover-border-color: var(--border-default);
    --fc-button-active-bg-color: var(--interactive-primary);
    --fc-button-active-border-color: var(--interactive-primary);
    --fc-today-bg-color: rgba(59, 130, 246, 0.08);
    --fc-event-bg-color: var(--interactive-primary);
    --fc-event-border-color: var(--interactive-primary);
    --fc-event-text-color: white;
    --fc-page-bg-color: var(--surface-elevated);
    --fc-neutral-bg-color: var(--surface-secondary);
    --fc-list-event-hover-bg-color: var(--surface-secondary);
}

.fullcalendar-wrapper .fc {
    font-family: inherit;
}

.fullcalendar-wrapper .fc-toolbar-title {
    font-size: 1.25rem !important;
    font-weight: 600;
    color: var(--text-primary);
}

.fullcalendar-wrapper .fc-button {
    font-size: 0.875rem !important;
    font-weight: 500;
    padding: 0.5rem 1rem !important;
    border-radius: 0.5rem !important;
    transition: all 0.15s ease;
}

.fullcalendar-wrapper .fc-button-group > .fc-button {
    border-radius: 0 !important;
}

.fullcalendar-wrapper .fc-button-group > .fc-button:first-child {
    border-radius: 0.5rem 0 0 0.5rem !important;
}

.fullcalendar-wrapper .fc-button-group > .fc-button:last-child {
    border-radius: 0 0.5rem 0.5rem 0 !important;
}

.fullcalendar-wrapper .fc-button-active {
    background-color: var(--interactive-primary) !important;
    border-color: var(--interactive-primary) !important;
    color: white !important;
}

.fullcalendar-wrapper .fc-daygrid-day-number {
    color: var(--text-secondary);
    font-weight: 500;
    padding: 0.5rem;
}

.fullcalendar-wrapper .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
    background-color: var(--interactive-primary);
    color: white;
    border-radius: 9999px;
    width: 1.75rem;
    height: 1.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.fullcalendar-wrapper .fc-col-header-cell-cushion {
    color: var(--text-secondary);
    font-weight: 500;
    font-size: 0.875rem;
    text-transform: uppercase;
    padding: 0.75rem 0;
}

/* User Events - Polished with gradient and better contrast */
.fullcalendar-wrapper .fc-event.user-event {
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
    padding: 4px 8px;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.fullcalendar-wrapper .fc-event.user-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.fullcalendar-wrapper .fc-event.user-event .fc-event-main,
.fullcalendar-wrapper .fc-event.user-event .fc-event-title {
    color: white;
    font-weight: 500;
}

.fullcalendar-wrapper .fc-event.user-event .fc-event-time {
    color: rgba(255, 255, 255, 0.85);
    font-weight: 400;
}

/* Dark mode user events - slightly brighter */
:root.dark .fullcalendar-wrapper .fc-event.user-event,
.dark .fullcalendar-wrapper .fc-event.user-event {
    background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

:root.dark .fullcalendar-wrapper .fc-event.user-event:hover,
.dark .fullcalendar-wrapper .fc-event.user-event:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
}

/* Holiday Events - styled as visible tags */
.fullcalendar-wrapper .fc-event.holiday-event {
    font-size: 0.7rem !important;
    font-weight: 600 !important;
    padding: 3px 8px !important;
    cursor: pointer;
    border-radius: 4px !important;
}

.fullcalendar-wrapper .fc-daygrid-event.holiday-event,
.fullcalendar-wrapper .fc-event.holiday-event {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
    border: none !important;
    color: white !important;
    box-shadow: 0 1px 3px rgba(220, 38, 38, 0.3);
}

.fullcalendar-wrapper .fc-daygrid-event.holiday-event:hover,
.fullcalendar-wrapper .fc-event.holiday-event:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.4);
}

/* Holiday event title text */
.fullcalendar-wrapper .fc-event.holiday-event .fc-event-title,
.fullcalendar-wrapper .fc-event.holiday-event .fc-event-main {
    color: white !important;
}

/* List view holidays */
.fullcalendar-wrapper .fc-list-event.holiday-event {
    background-color: rgba(220, 38, 38, 0.1) !important;
}

.fullcalendar-wrapper .fc-list-event.holiday-event .fc-list-event-dot {
    border-color: #dc2626 !important;
}

/* List view user events */
.fullcalendar-wrapper .fc-list-event:not(.holiday-event) .fc-list-event-dot {
    border-color: #3b82f6 !important;
}

.fullcalendar-wrapper .fc-timegrid-slot-label-cushion,
.fullcalendar-wrapper .fc-timegrid-axis-cushion {
    color: var(--text-tertiary);
    font-size: 0.75rem;
}

.fullcalendar-wrapper .fc-list-day-cushion {
    background-color: var(--surface-secondary) !important;
}

.fullcalendar-wrapper .fc-list-event:hover td {
    background-color: var(--surface-secondary);
}

/* Scrollbar styling */
.fullcalendar-wrapper .fc-scroller::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.fullcalendar-wrapper .fc-scroller::-webkit-scrollbar-track {
    background: var(--surface-secondary);
    border-radius: 4px;
}

.fullcalendar-wrapper .fc-scroller::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 4px;
}

.fullcalendar-wrapper .fc-scroller::-webkit-scrollbar-thumb:hover {
    background: var(--text-tertiary);
}

/* View Transitions */
.fullcalendar-wrapper.is-transitioning .fc-view-harness {
    opacity: 0;
    transform: translateY(10px) scale(0.99);
    filter: blur(4px);
}

.fullcalendar-wrapper .fc-view-harness {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    opacity: 1;
    transform: translateY(0) scale(1);
    filter: blur(0);
}
</style>
