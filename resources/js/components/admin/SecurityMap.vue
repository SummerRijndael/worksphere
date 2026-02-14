<script setup>
import { onMounted, ref, watch, onUnmounted } from 'vue';
import { useThemeStore } from '@/stores/theme';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.heat';

const props = defineProps({
    data: {
        type: Array,
        default: () => []
    },
    loading: {
        type: Boolean,
        default: false
    }
});

const themeStore = useThemeStore();
const mapContainer = ref(null);
let map = null;
let currentTileLayer = null;
let heatLayer = null;
let markers = [];

const getTileUrl = () => {
    return themeStore.isDark 
        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
        : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
};

const updateTileLayer = () => {
    if (!map) return;
    
    if (currentTileLayer) {
        map.removeLayer(currentTileLayer);
    }
    
    currentTileLayer = L.tileLayer(getTileUrl(), {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 19
    });
    
    currentTileLayer.addTo(map);
};

const initMap = () => {
    if (!mapContainer.value) return;

    map = L.map(mapContainer.value, {
        center: [20, 0],
        zoom: 2,
        minZoom: 2,
        worldCopyJump: true,
        zoomControl: false,
        attributionControl: false
    });

    updateTileLayer();

    L.control.zoom({ position: 'bottomright' }).addTo(map);
};



watch(() => themeStore.isDark, () => {
    updateTileLayer();
});

const updateMapData = () => {
    if (!map) return;

    // Remove existing layers/markers
    if (heatLayer) {
        map.removeLayer(heatLayer);
    }
    markers.forEach(m => map.removeLayer(m));
    markers = [];

    if (!props.data || !Array.isArray(props.data) || props.data.length === 0) return;

    // Prepare Heatmap Data (Replicated for wrapping)
    const heatPoints = [];
    props.data.forEach(d => {
        [0, -360, 360].forEach(offset => {
            heatPoints.push([d.lat, d.lng + offset, d.intensity]);
        });
    });
    
    // @ts-ignore - leaflet.heat is not in types
    heatLayer = L.heatLayer(heatPoints, {
        radius: 20,
        blur: 15,
        maxZoom: 10,
        gradient: {
            0.4: 'blue',
            0.6: 'cyan',
            0.7: 'lime',
            0.8: 'yellow',
            1.0: 'red'
        }
    }).addTo(map);

    // Add dynamic markers for significant threats (Replicated for wrapping)
    props.data.forEach(d => {
        if (d.count > 5) {
            [0, -360, 360].forEach(offset => {
                const marker = L.circleMarker([d.lat, d.lng + offset], {
                    radius: Math.min(10, 4 + d.count / 5),
                    fillColor: '#ef4444',
                    color: '#fff',
                    weight: 1,
                    opacity: 0.8,
                    fillOpacity: 0.4
                }).addTo(map);

                marker.bindPopup(`
                    <div class="p-2 dark:text-white">
                        <div class="font-bold text-sm text-red-500">${d.type}</div>
                        <div class="text-xs font-mono mt-1">${d.ip}</div>
                        <div class="text-xs text-[var(--text-secondary)] mt-1">${d.location}</div>
                        <div class="text-xs font-bold mt-1">Hits: ${d.count}</div>
                    </div>
                `, { className: 'custom-map-popup' });
                
                markers.push(marker);
            });
        }
    });

    // Fit bounds if we have points
    if (props.data.length > 0 && props.data.length < 5) {
        const bounds = L.latLngBounds(props.data.map(d => [d.lat, d.lng]));
        map.fitBounds(bounds, { padding: [50, 50] });
    }
};

onMounted(() => {
    initMap();
    updateMapData();
});

watch(() => props.data, () => {
    updateMapData();
}, { deep: true });

onUnmounted(() => {
    if (map) {
        map.remove();
    }
});
</script>

<template>
    <div :class="[
        'relative w-full h-[500px] rounded-2xl overflow-hidden border border-(--border-default) transition-colors duration-300',
        themeStore.isDark ? 'bg-[#111]' : 'bg-[#f8f9fa]'
    ]">
        <div ref="mapContainer" class="w-full h-full z-10"></div>
        
        <!-- Overlay for loading -->
        <div v-if="loading" class="absolute inset-0 z-20 bg-black/40 flex items-center justify-center backdrop-blur-[2px]">
            <div class="flex flex-col items-center gap-3">
                <div class="w-10 h-10 border-4 border-(--interactive-primary) border-t-transparent rounded-full animate-spin"></div>
                <span class="text-sm font-medium text-white">Loading Threat Data...</span>
            </div>
        </div>

        <!-- Legend -->
        <div class="absolute bottom-6 left-6 z-20 bg-black/60 backdrop-blur-md p-4 rounded-xl border border-white/10 text-white shadow-2xl pointer-events-none">
            <h4 class="text-xs font-bold uppercase tracking-wider mb-3 text-red-400">Threat Origins</h4>
            <div class="space-y-2">
                <div class="flex items-center gap-3 text-xs">
                    <div class="w-3 h-3 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]"></div>
                    <span>High Activity</span>
                </div>
                <div class="flex items-center gap-3 text-xs opacity-80">
                    <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                    <span>Observation</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Leaflet dark theme tweaks */
.leaflet-container {
    background: transparent !important;
}

.custom-map-popup .leaflet-popup-content-wrapper {
    background: rgba(15, 15, 15, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
    border-radius: 12px;
}

.custom-map-popup .leaflet-popup-tip {
    background: rgba(15, 15, 15, 0.95);
}

.leaflet-popup-content {
    margin: 8px !important;
}
</style>
