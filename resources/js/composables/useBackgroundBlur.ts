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
                "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.32/wasm"
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
                        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.32/wasm"
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
        
        console.log(`[BackgroundBlur] Video dimensions: ${video.videoWidth}x${video.videoHeight}, mode: ${currentRunningMode}`);

        const draw = () => {
            if (!video || !canvas || !segmenter.value) {
                console.warn('[BackgroundBlur] draw() skipped: missing refs', { video: !!video, canvas: !!canvas, segmenter: !!segmenter.value });
                return;
            }
            // Check if video is still active/playing
            if (video.paused || video.ended) {
                console.warn('[BackgroundBlur] draw() skipped: video paused or ended');
                return;
            }
            
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

        // Reusable ImageData for mask rendering (avoids per-frame allocation)
        let maskImageData: ImageData | null = null;
        let maskImageDataWidth = 0;
        let maskImageDataHeight = 0;

        let frameCount = 0;
        const renderResult = (result: ImageSegmenterResult) => {
            if (!ctx || !canvas) {
                 animationFrameId = requestAnimationFrame(draw);
                 return;
            }
            
            frameCount++;
            if (frameCount <= 3) {
                console.log(`[BackgroundBlur] Frame ${frameCount}:`, {
                    confidenceMasks: result.confidenceMasks?.length ?? 0,
                    categoryMask: !!result.categoryMask,
                });
            }

            if (result.confidenceMasks && result.confidenceMasks.length > 0) {
                 // Confidence mask: getAsFloat32Array returns values [0,1] per pixel
                 const mask = result.confidenceMasks[0];
                 const width = mask.width;
                 const height = mask.height;
                 const maskData = mask.getAsFloat32Array();

                 // Reuse or create ImageData for mask
                 if (!maskImageData || maskImageDataWidth !== width || maskImageDataHeight !== height) {
                     maskImageData = new ImageData(width, height);
                     maskImageDataWidth = width;
                     maskImageDataHeight = height;
                 }
                 const data = maskImageData.data;

                 for (let i = 0; i < maskData.length; i++) {
                     // maskData[i] is confidence 0..1 that this pixel is a person
                     const alpha = Math.round(maskData[i] * 255);
                     const j = i * 4;
                     data[j] = alpha;       // R (white where person)
                     data[j + 1] = alpha;   // G
                     data[j + 2] = alpha;   // B
                     data[j + 3] = 255;     // A (fully opaque — use luminance as mask)
                 }

                 // Convert ImageData to ImageBitmap for efficient canvas drawing
                 createImageBitmap(maskImageData).then(bmp => {
                     drawComposition(bmp, true);
                 }).catch(() => {
                     // Fallback: draw raw video if bitmap creation fails
                     if (ctx && canvas) {
                         ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                     }
                     animationFrameId = requestAnimationFrame(draw);
                 });
            } else if (result.categoryMask) {
                 // CPU Path: Process category mask (Multiclass: 0=bg, >0=person)
                 const mask = result.categoryMask;
                 const width = mask.width;
                 const height = mask.height;
                 const maskData = mask.getAsUint8Array();
                 
                 if (!maskImageData || maskImageDataWidth !== width || maskImageDataHeight !== height) {
                     maskImageData = new ImageData(width, height);
                     maskImageDataWidth = width;
                     maskImageDataHeight = height;
                 }
                 const data = maskImageData.data;
                 
                 for (let i = 0; i < maskData.length; i++) {
                     const val = maskData[i]; // Class index
                     const lum = val > 0 ? 255 : 0; // 0 = background
                     
                     const j = i * 4;
                     data[j] = lum;       // R
                     data[j + 1] = lum;   // G
                     data[j + 2] = lum;   // B
                     data[j + 3] = 255;   // A
                 }
                 
                 createImageBitmap(maskImageData).then(bmp => {
                     drawComposition(bmp, true);
                 }).catch(() => {
                     if (ctx && canvas) {
                         ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                     }
                     animationFrameId = requestAnimationFrame(draw);
                 });
            } else {
                 // Fallback: draw raw video
                 ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                 animationFrameId = requestAnimationFrame(draw);
            }
        };
        
        const drawComposition = (mask: ImageBitmap, shouldClose: boolean) => {
             if (!ctx || !canvas || !video) return;

             const w = canvas.width;
             const h = canvas.height;

             ctx.clearRect(0, 0, w, h);
             ctx.save();
             
             // 1. Draw the luminance mask (white=person, black=background)
             ctx.globalCompositeOperation = 'source-over';
             ctx.drawImage(mask, 0, 0, w, h);
             
             // 2. Source-in: draw video only where mask is white (person)
             ctx.globalCompositeOperation = 'source-in';
             ctx.drawImage(video, 0, 0, w, h);
             
             // 3. Destination-over: draw blurred video behind the person
             ctx.globalCompositeOperation = 'destination-over';
             ctx.filter = 'blur(15px)';
             ctx.drawImage(video, 0, 0, w, h);
             ctx.filter = 'none';
             
             ctx.restore();
             
             if (shouldClose) {
                mask.close();
             }
             
            animationFrameId = requestAnimationFrame(draw);
        };
        
        draw();

        const processedStream = canvas.captureStream(30); 
        const outputTrack = processedStream.getVideoTracks()[0];
        console.log('[BackgroundBlur] Returning canvas track:', outputTrack?.id, 'enabled:', outputTrack?.enabled);
        return outputTrack;
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
