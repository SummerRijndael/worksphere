<script setup>
import { ref, computed, onMounted, watch } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import interactionPlugin from "@fullcalendar/interaction";
import { format } from "date-fns";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import api from "@/lib/api";
import { useRouter } from "vue-router";

const router = useRouter();
const calendarRef = ref(null);
const events = ref([]);
const holidays = ref([]);
const currentTitle = ref("");

const props = defineProps({
    showHolidays: {
        type: Boolean,
        default: true,
    },
    countryCode: {
        type: String,
        default: "US",
    },
    clickable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(["dateClick", "eventClick"]);

// Combined events
const allEvents = computed(() => {
    const userEvents = events.value.map((event) => ({
        id: event.id,
        title: event.title,
        start: event.start_time,
        end: event.end_time,
        allDay: event.is_all_day,
        classNames: ["user-event"],
        extendedProps: { type: "event" },
    }));

    if (!props.showHolidays) {
        return userEvents;
    }

    return [...userEvents, ...holidays.value];
});

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, interactionPlugin],
    initialView: "dayGridMonth",
    headerToolbar: false, // We'll build custom header
    height: "auto",
    contentHeight: "auto",
    dayMaxEvents: 2,
    fixedWeekCount: false,
    showNonCurrentDates: true,
    events: allEvents.value,
    selectable: props.clickable,
    dateClick: handleDateClick,
    eventClick: handleEventClick,
    datesSet: handleDatesSet,
    dayHeaderFormat: { weekday: "narrow" },
    eventDisplay: "dot",
    eventClassNames: (arg) => {
        if (arg.event.extendedProps?.type === "holiday") {
            return ["mini-holiday"];
        }
        return ["mini-event"];
    },
}));

async function fetchEvents(start, end) {
    try {
        const response = await api.get("/api/calendar/events", {
            params: {
                start: format(start, "yyyy-MM-dd HH:mm:ss"),
                end: format(end, "yyyy-MM-dd HH:mm:ss"),
            },
        });
        events.value = response.data.data.map((event) => ({
            ...event,
            start_time: new Date(event.start_time),
            end_time: new Date(event.end_time),
        }));
    } catch (error) {
        console.error("Failed to fetch events:", error);
    }
}

async function fetchHolidays(start, end) {
    if (!props.showHolidays) return;

    try {
        const response = await api.get("/api/holidays", {
            params: {
                country: props.countryCode,
                start: format(start, "yyyy-MM-dd"),
                end: format(end, "yyyy-MM-dd"),
            },
        });
        holidays.value = response.data.map((h) => ({
            ...h,
            classNames: ["mini-holiday"],
        }));
    } catch (error) {
        // Fail silently for holidays
    }
}

function handleDatesSet(dateInfo) {
    currentTitle.value = format(dateInfo.view.currentStart, "MMMM yyyy");
    fetchEvents(dateInfo.start, dateInfo.end);
    fetchHolidays(dateInfo.start, dateInfo.end);
}

function handleDateClick(info) {
    if (props.clickable) {
        emit("dateClick", info.date);
        router.push("/calendar");
    }
}

function handleEventClick(info) {
    emit("eventClick", info.event);
    router.push("/calendar");
}

function prev() {
    const calendarApi = calendarRef.value?.getApi();
    calendarApi?.prev();
}

function next() {
    const calendarApi = calendarRef.value?.getApi();
    calendarApi?.next();
}

function today() {
    const calendarApi = calendarRef.value?.getApi();
    calendarApi?.today();
}
</script>

<template>
    <div class="mini-calendar">
        <!-- Custom Header -->
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-[var(--text-primary)] text-sm">
                {{ currentTitle }}
            </h3>
            <div class="flex items-center gap-1">
                <button
                    @click="prev"
                    class="p-1 rounded hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] transition-colors"
                >
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <button
                    @click="today"
                    class="px-2 py-0.5 text-xs rounded hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] transition-colors"
                >
                    Today
                </button>
                <button
                    @click="next"
                    class="p-1 rounded hover:bg-[var(--surface-secondary)] text-[var(--text-secondary)] transition-colors"
                >
                    <ChevronRight class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Calendar -->
        <FullCalendar ref="calendarRef" :options="calendarOptions" />
    </div>
</template>

<style>
.mini-calendar .fc {
    font-family: inherit;
    font-size: 0.75rem;
}

.mini-calendar .fc-theme-standard td,
.mini-calendar .fc-theme-standard th {
    border-color: var(--border-default);
}

.mini-calendar .fc-theme-standard .fc-scrollgrid {
    border: none;
}

.mini-calendar .fc-col-header-cell {
    padding: 0.5rem 0;
    font-weight: 500;
    color: var(--text-tertiary);
    text-transform: uppercase;
    font-size: 0.65rem;
    border: none !important;
    background: transparent;
}

.mini-calendar .fc-daygrid-day {
    min-height: 2.5rem !important;
}

.mini-calendar .fc-daygrid-day-frame {
    min-height: 2.5rem !important;
    padding: 2px;
}

.mini-calendar .fc-daygrid-day-number {
    font-size: 0.75rem;
    padding: 4px;
    color: var(--text-secondary);
    font-weight: 500;
}

.mini-calendar .fc-day-today {
    background-color: transparent !important;
}

.mini-calendar .fc-day-today .fc-daygrid-day-number {
    background-color: var(--interactive-primary);
    color: white;
    border-radius: 50%;
    width: 1.5rem;
    height: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.mini-calendar .fc-day-other .fc-daygrid-day-number {
    color: var(--text-muted);
}

.mini-calendar .fc-daygrid-day:hover {
    background-color: var(--surface-secondary);
    cursor: pointer;
}

/* Event dots */
.mini-calendar .fc-daygrid-event-dot {
    border-color: var(--interactive-primary);
}

.mini-calendar .fc-event.mini-holiday .fc-daygrid-event-dot {
    border-color: var(--color-error);
}

.mini-calendar .fc-daygrid-day-events {
    min-height: 0 !important;
    margin-top: 0 !important;
}

.mini-calendar .fc-daygrid-event {
    font-size: 0 !important;
}

.mini-calendar .fc-daygrid-more-link {
    font-size: 0.6rem;
    color: var(--text-muted);
}

/* Hide event text, show only dots */
.mini-calendar .fc-event-title {
    display: none;
}

.mini-calendar .fc-event-time {
    display: none;
}
</style>
