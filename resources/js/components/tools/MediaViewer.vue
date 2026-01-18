<script setup>
import { onMounted } from 'vue';
import { 
    X, 
    Download, 
    Trash, 
    Info, 
    ChevronLeft, 
    ChevronRight,
    Play,
    Pause
} from 'lucide-vue-next';
import { initMediaViewer } from '@/components/tools/image-viewer.jsx';
import '@/../css/tools/image-viewer.css'; // Adjust path based on location

onMounted(() => {
    initMediaViewer();
});
</script>

<template>
    <div id="media-viewer" class="mv hidden">
        <div id="mv-backdrop" class="mv-backdrop"></div>
        
        <div class="mv-toolbar">
            <div class="mv-toolbar-left">
                <!-- Counter -->
                <div id="mv-counter" class="mv-counter-pill">
                    1 / 1
                </div>
            </div>

            <div class="mv-toolbar-right">
                 <!-- Play/Pause (will be moved by JS) -->
                 
                 <a id="mv-download" href="#" class="mv-icon-btn" download title="Download">
                    <Download class="h-5 w-5" />
                 </a>
                 <button id="mv-info" class="mv-icon-btn" title="Info">
                    <Info class="h-5 w-5" />
                 </button>
                 <button id="mv-delete" class="mv-icon-btn text-red-400 hover:text-red-300" title="Delete">
                    <Trash class="h-5 w-5" />
                 </button>
                 <button id="mv-close" class="mv-icon-btn" title="Close">
                    <X class="h-6 w-6" />
                 </button>
            </div>
        </div>

        <div id="mv-stage" class="mv-stage">
            <div class="mv-image-container">
                <img id="mv-image" class="mv-image" src="" alt="" style="display: none;" />
                <!-- Video will be injected here -->
            </div>
        </div>

        <!-- Navigation -->
        <button id="mv-prev" class="mv-nav-btn mv-prev">
             <ChevronLeft class="h-8 w-8" />
        </button>
        <button id="mv-next" class="mv-nav-btn mv-next">
             <ChevronRight class="h-8 w-8" />
        </button>

        <!-- Bottom Sheet (Thumbs) -->
        <div class="mv-bottom-sheet">
            <div id="mv-thumbs-wrapper" class="mv-thumbs-mask">
                <div id="mv-thumbs" class="mv-thumbs"></div>
            </div>
        </div>

         <!-- Info Panel -->
         <div id="mv-info-panel" class="mv-info-panel">
             <div class="mv-info-header">
                 <h3 class="mv-info-title">Details</h3>
                 <button id="mv-info-close" class="mv-icon-btn mv-info-close-btn">
                     <X class="h-5 w-5" />
                 </button>
             </div>
             <div id="mv-info-content" class="mv-info-content"></div>
        </div>
    </div>
</template>
