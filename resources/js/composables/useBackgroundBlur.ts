import {
    ImageSegmenter,
    FilesetResolver,
    type ImageSegmenterResult,
} from "@mediapipe/tasks-vision";
import { ref, shallowRef, onUnmounted } from "vue";

export function useBackgroundBlur() {
    const segmenter = shallowRef<ImageSegmenter | null>(null);
    const isLoaded = ref(false);
    const isLoading = ref(false);
    const error = ref<string | null>(null);

    // Canvas for processing
    let canvas: HTMLCanvasElement | null = null;
    let ctx: CanvasRenderingContext2D | null = null;
    let animationFrameId: number | null = null;
    let currentRunningMode: 'VIDEO' | 'IMAGE' = 'VIDEO';
    
    // Internal refs to keep tracks alive
    let sourceVideo: HTMLVideoElement | null = null;
    
    async function loadModel() {
        if (isLoaded.value || isLoading.value) return;
        isLoading.value = true;
        try {
             const vision = await FilesetResolver.forVisionTasks(
                "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/wasm"
            );
            segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                baseOptions: {
                    modelAssetPath:
                        "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite",
                    delegate: "GPU",
                },
                runningMode: "VIDEO" as const,
                outputCategoryMask: false, 
                outputConfidenceMasks: true,
            });
            isLoaded.value = true;
            currentRunningMode = 'VIDEO';
            console.log("[BackgroundBlur] Model loaded (GPU)");
        } catch(e) {
            console.warn("GPU Failed, trying CPU", e);
             try {
                    const vision = await FilesetResolver.forVisionTasks(
                        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/wasm"
                    );
                    segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                        baseOptions: {
                            modelAssetPath:
                                "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_multiclass_256x256/float32/latest/selfie_multiclass_256x256.tflite",
                            delegate: "CPU",
                        },
                        runningMode: "IMAGE" as const, // Use IMAGE mode for CPU to avoid implicit GPU requirements
                        outputCategoryMask: false, 
                        outputConfidenceMasks: true, // Try confidence mask on CPU to avoid TensorsToSegmentationCalculator GPU error
                    });
                     isLoaded.value = true;
                     currentRunningMode = 'IMAGE';
                     console.log("[BackgroundBlur] Model loaded (CPU)");
             } catch (retryError) {
                 error.value = "Failed to load blur model";
                 console.error(retryError);
             }
        } finally {
            isLoading.value = false;
        }
    }

    async function startBlur(
        rawTrack: MediaStreamTrack
    ): Promise<MediaStreamTrack> {
        if (!isLoaded.value) {
            await loadModel();
        }
        if (!segmenter.value) {
            throw new Error("Segmenter not loaded");
        }

        // Setup canvas
        if (!canvas) {
            canvas = document.createElement("canvas");
            // Initial size, will be updated when video plays
            canvas.width = 640;
            canvas.height = 480;
        }
        ctx = canvas.getContext("2d", { willReadFrequently: true });

        const video = document.createElement("video");
        sourceVideo = video;
        video.autoplay = true;
        video.playsInline = true;
        video.muted = true;
        const sourceStream = new MediaStream([rawTrack]);
        video.srcObject = sourceStream;

        await video.play();

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        const draw = () => {
            if (!video || !canvas || !segmenter.value) return;
            // Check if video is still active/playing
            if (video.paused || video.ended) return;
            
            try {
                if (currentRunningMode === 'IMAGE') {
                    const result = segmenter.value.segment(video);
                    renderResult(result);
                } else {
                    const startTime = performance.now();
                    segmenter.value.segmentForVideo(video, startTime, renderResult);
                }
            } catch (e) {
                console.error("Segmentation error:", e);
                animationFrameId = requestAnimationFrame(draw);
            }
        };

        const renderResult = (result: ImageSegmenterResult) => {
            if (!ctx || !canvas) {
                 animationFrameId = requestAnimationFrame(draw);
                 return;
            }
            
            // Handle GPU (Confidence Mask) vs CPU (Category Mask)
            let personMaskBitmap: ImageBitmap | null = null;
            let needsClose = false;

            if (result.confidenceMasks && result.confidenceMasks[1]) {
                 // GPU Path: Use simple confidence mask
                 personMaskBitmap = result.confidenceMasks[1].getAsImageBitmap(); 
                 needsClose = true;
            } else if (result.categoryMask) {
                 // CPU Path: Process category mask (Multiclass: 0=bg, >0=person)
                 const mask = result.categoryMask;
                 const width = mask.width;
                 const height = mask.height;
                 const maskData = mask.getAsUint8Array();
                 
                 // Create an ImageData to draw
                 const imageData = new ImageData(width, height);
                 const data = imageData.data;
                 
                 for (let i = 0; i < maskData.length; i++) {
                     const val = maskData[i]; // Class index
                     const isPerson = val > 0; // 0 is background
                     const alpha = isPerson ? 255 : 0;
                     
                     const j = i * 4;
                     data[j] = 0;     // R (Doesn't matter, we use as mask)
                     data[j + 1] = 0; // G
                     data[j + 2] = 0; // B
                     data[j + 3] = alpha; // Alpha defines the mask
                 }
                 
                 // Temporary canvas to convert ImageData to ImageBitmap (or just draw ImageData)
                 // Drawing ImageData directly to main canvas is fine for CPU fallback
                 // But we need to scale it if mask size != video size? 
                 // Multiclass is 256x256. Canvas is Video Size (e.g. 640x480).
                 // putImageData requires strict size.
                 // So we must use an offscreen canvas to scale.
                 
                 // Note: Creating new canvas/ImageData every frame is slow. 
                 // Optimized approach: Reuse a small canvas.
                 // For now, let's just create a quick bitmap via createImageBitmap which handles scaling when drawn.
                 createImageBitmap(imageData).then(bmp => {
                     drawComposition(bmp, true);
                 });
                 return; // Async completion
            }

            if (personMaskBitmap) {
                drawComposition(personMaskBitmap, needsClose);
            } else {
                 // Fallback: draw raw video
                 ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                 animationFrameId = requestAnimationFrame(draw);
            }
        };
        
        const drawComposition = (mask: ImageBitmap, shouldClose: boolean) => {
             if (!ctx || !canvas || !video) return;

             ctx.clearRect(0, 0, canvas.width, canvas.height);
             ctx.save();
             
             // 1. Draw Mask
             // We draw the mask scaled to the canvas size
             ctx.drawImage(mask, 0, 0, canvas.width, canvas.height);
             
             // 2. Keep Person (Source-In)
             ctx.globalCompositeOperation = 'source-in';
             ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
             
             // 3. Draw Blurred Background (Destination-Over)
             ctx.globalCompositeOperation = 'destination-over';
             ctx.filter = 'blur(15px)';
             ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
             ctx.filter = 'none';
             
             ctx.restore();
             
             if (shouldClose && typeof (mask as any).close === 'function') {
                (mask as any).close();
             }
             
            // For IMAGE mode (sync), we need to schedule next frame manually here
            // For VIDEO mode (async callback), we also do it here
            animationFrameId = requestAnimationFrame(draw);
        };
        
        draw();

        const processedStream = canvas.captureStream(30); 
        return processedStream.getVideoTracks()[0];
    }
    
    function stopProcessing() {
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
        }
        if (sourceVideo) {
            sourceVideo.pause();
            sourceVideo.srcObject = null;
            sourceVideo = null;
        }
        // segmenter.value?.close(); // Optional, keep loaded for reuse
    }
    
    onUnmounted(() => {
        stopProcessing();
    });

    return {
        loadModel,
        startBlur,
        stopProcessing,
        isLoaded,
        isLoading,
        error
    };
}
