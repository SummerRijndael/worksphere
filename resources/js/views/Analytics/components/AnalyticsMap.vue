<script setup lang="ts">
import { onMounted, ref, watch, computed } from "vue";
import { Card } from "@/components/ui";
import { useAnalyticsStore } from "@/stores/analytics";
import { useThemeStore } from "@/stores/theme";
import L from "leaflet";
import "leaflet/dist/leaflet.css";

const store = useAnalyticsStore();
const themeStore = useThemeStore();
const mapContainer = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let markers: L.LayerGroup | null = null;
let currentTileLayer: L.TileLayer | null = null;

const mapViews = ["Overview", "By Device"] as const;
const selectedView = ref<(typeof mapViews)[number]>("Overview");

const deviceColors = {
    desktop: "#3b82f6", // blue
    mobile: "#10b981", // green
    tablet: "#f59e0b", // orange
};

// Fix Leaflet icon paths
delete (L.Icon.Default.prototype as any)._getIconUrl;
L.Icon.Default.mergeOptions({
    iconRetinaUrl: new URL(
        "leaflet/dist/images/marker-icon-2x.png",
        import.meta.url,
    ).href,
    iconUrl: new URL("leaflet/dist/images/marker-icon.png", import.meta.url)
        .href,
    shadowUrl: new URL(
        "leaflet/dist/images/marker-shadow.png",
        import.meta.url,
    ).href,
});

// FIX 1: Use city|country|iso_code as the dedup key instead of raw lat/lon.
// The old key `${stat.lat},${stat.lon}` collapsed ALL points that shared
// the same coordinates (e.g. same city with multiple device types, or
// backend entries with lat:0/lon:0 as a null fallback) into a single point.
const aggregatedStats = computed(() => {
    if (selectedView.value === "By Device") return store.geoStats;

    const aggregated: Record<string, any> = {};
    store.geoStats.forEach((stat) => {
        const key = `${stat.city}|${stat.country}|${stat.iso_code}`;
        if (!aggregated[key]) {
            aggregated[key] = { ...stat, count: 0 };
        }
        aggregated[key].count += stat.count;
    });
    return Object.values(aggregated);
});

onMounted(() => {
    if (mapContainer.value) {
        initMap();
    }
});

// FIX 2: Watch store.geoStats directly with immediate:true instead of
// watching the computed. This catches three cases the old watcher missed:
//   a) Data already in store when component mounts (e.g. cached/period switch)
//   b) Data arriving after mount from the parent's fetchAll()
//   c) Period changes that replace geoStats with a fresh array reference
watch(
    () => store.geoStats,
    () => {
        if (map && markers) refreshMarkers();
    },
    { deep: true, immediate: true },
);

// Re-render when user switches between Overview / By Device tabs
watch(
    () => selectedView.value,
    () => {
        if (map && markers) refreshMarkers();
    },
);

watch(
    () => themeStore.isDark,
    () => {
        updateTileLayer();
    },
);

const getTileUrl = () => {
    return themeStore.isDark
        ? "https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png"
        : "https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png";
};

const updateTileLayer = () => {
    if (!map) return;

    if (currentTileLayer) {
        map.removeLayer(currentTileLayer);
    }

    currentTileLayer = L.tileLayer(getTileUrl(), {
        attribution:
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: "abcd",
        maxZoom: 19,
    });

    currentTileLayer.addTo(map);
};

function initMap() {
    if (!mapContainer.value) return;

    map = L.map(mapContainer.value, {
        minZoom: 2,
        worldCopyJump: true,
    }).setView([20, 0], 2);

    updateTileLayer();

    // FIX 3: Don't call refreshMarkers() here — at this point geoStats is
    // still empty (fetchAll hasn't resolved yet). The immediate watcher above
    // will fire right after initMap() and handle the initial render once
    // data arrives, avoiding a wasted render pass with zero points.
    markers = L.layerGroup().addTo(map);
}

function refreshMarkers() {
    if (!map || !markers) return;

    markers.clearLayers();

    const points = aggregatedStats.value;
    console.log(`[AnalyticsMap] Rendering ${points.length} map point(s)`, points);

    points.forEach((stat) => {
        // FIX 4: Skip entries where backend returned lat:0/lon:0 as a null
        // fallback — they all land in the ocean and collapse into one dot.
        if (!stat.lat || !stat.lon || (stat.lat === 0 && stat.lon === 0)) return;

        const lat = Number(stat.lat);
        const lon = Number(stat.lon);

        const popupContent = `
            <div class="text-xs p-1">
                <div class="font-bold border-b border-gray-100 mb-1 pb-1">${stat.city || "Unknown City"}, ${stat.country}</div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-gray-500 capitalize">${selectedView.value === "By Device" ? stat.device_type : "Total Visits"}</span>
                    <span class="font-semibold">${stat.count}</span>
                </div>
            </div>
        `;

        let fillColor = "#3b82f6";
        let color = "#2563eb";

        if (selectedView.value === "By Device") {
            fillColor = (deviceColors as any)[stat.device_type] || "#6b7280";
            color = fillColor;
        } else {
            // Overview: warmer color = more visits
            if (stat.count > 50) {
                fillColor = "#ef4444"; // red
                color = "#b91c1c";
            } else if (stat.count > 10) {
                fillColor = "#f97316"; // orange
                color = "#c2410c";
            }
        }

        [0, -360, 360].forEach((offset) => {
            L.circleMarker([lat, lon + offset], {
                radius: Math.min(Math.max(Math.sqrt(stat.count) * 4, 6), 25),
                fillColor,
                color,
                weight: 1,
                opacity: 0.8,
                fillOpacity: 0.5,
            })
                .bindPopup(popupContent, { className: "custom-map-popup" })
                .addTo(markers!);
        });
    });
}
</script>

<template>
    <Card padding="lg">
        <div
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4"
        >
            <h2 class="text-lg font-semibold text-(--text-primary)">
                Visitor Locations
            </h2>
            <div
                class="flex p-1 bg-(--surface-secondary) rounded-lg border border-(--border-muted)"
            >
                <button
                    v-for="view in mapViews"
                    :key="view"
                    @click="selectedView = view"
                    :class="[
                        'px-4 py-1.5 text-xs font-medium rounded-md transition-all duration-200',
                        selectedView === view
                            ? 'bg-(--surface-primary) text-(--text-primary) shadow-sm'
                            : 'text-(--text-secondary) hover:text-(--text-primary)',
                    ]"
                >
                    {{ view }}
                </button>
            </div>
        </div>

        <div class="relative group">
            <div
                class="h-96 w-full rounded-xl overflow-hidden z-0 border border-(--border-muted)"
                ref="mapContainer"
            ></div>

            <!-- Legend Overlay -->
            <div
                v-if="selectedView === 'By Device'"
                class="absolute bottom-4 left-4 z-10 bg-(--surface-primary)/90 backdrop-blur-sm p-3 rounded-lg border border-(--border-muted) shadow-lg"
            >
                <div
                    class="text-[10px] font-bold text-(--text-muted) uppercase tracking-wider mb-2"
                >
                    Device Types
                </div>
                <div class="space-y-2">
                    <div
                        v-for="(color, device) in deviceColors"
                        :key="device"
                        class="flex items-center gap-2"
                    >
                        <div
                            class="w-2.5 h-2.5 rounded-full ring-1 ring-black/5"
                            :style="{ backgroundColor: color }"
                        ></div>
                        <span
                            class="text-xs capitalize text-(--text-secondary)"
                            >{{ device }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Overview Legend -->
            <div
                v-else
                class="absolute bottom-4 left-4 z-10 bg-(--surface-primary)/90 backdrop-blur-sm p-3 rounded-lg border border-(--border-muted) shadow-lg"
            >
                <div
                    class="text-[10px] font-bold text-(--text-muted) uppercase tracking-wider mb-2"
                >
                    Visitor Density
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1.5">
                        <div
                            class="w-2.5 h-2.5 rounded-full bg-[#3b82f6]"
                        ></div>
                        <span class="text-[10px] text-(--text-secondary)"
                            >Low</span
                        >
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div
                            class="w-2.5 h-2.5 rounded-full bg-landing-cta"
                        ></div>
                        <span class="text-[10px] text-(--text-secondary)"
                            >Med</span
                        >
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div
                            class="w-2.5 h-2.5 rounded-full bg-[#ef4444]"
                        ></div>
                        <span class="text-[10px] text-(--text-secondary)"
                            >High</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </Card>
</template>

<style scoped>
/* Ensure map tiles render correctly */
/* Ensure map tiles render correctly */
/* Removed harmful z-index override that was flattening map layers */
</style>