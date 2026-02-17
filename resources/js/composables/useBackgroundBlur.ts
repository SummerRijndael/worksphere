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
    
    // Offscreen canvases for performance
    let blurCanvas: HTMLCanvasElement | null = null;
    let blurCtx: CanvasRenderingContext2D | null = null;
    let personCanvas: HTMLCanvasElement | null = null;
    let personCtx: CanvasRenderingContext2D | null = null;
    let cachedBgCanvas: HTMLCanvasElement | null = null;
    let cachedBgCtx: CanvasRenderingContext2D | null = null;
    
    // Auto-framing state
    let framing = {
        centerX: 0.5,
        centerY: 0.5,
        zoom: 1.0,
        targetCenterX: 0.5,
        targetCenterY: 0.5,
        targetZoom: 1.0
    };
    
    // Internal refs to keep tracks alive
    let sourceVideo: HTMLVideoElement | null = null;
    let bgImage: HTMLImageElement | null = null;
    let currentTrackId: string | null = null;
    let currentEffect: 'blur' | 'image' | 'none' = 'none';
    let currentImageUrl: string | undefined = undefined;
    let isAutoFramingEnabled = false;
    
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

    async function startVideoEffect(
        rawTrack: MediaStreamTrack,
        effect: 'blur' | 'image' = 'blur',
        imageUrl?: string,
        autoFraming: boolean = false
    ): Promise<MediaStreamTrack> {
        isAutoFramingEnabled = autoFraming;
        if (!isLoaded.value) {
            await loadModel();
        }
        if (!segmenter.value) {
            throw new Error("Segmenter not loaded");
        }

        if (animationFrameId && currentTrackId === rawTrack.id) {
            console.log("[BackgroundBlur] Updating effect for existing track:", effect, "autoFraming:", autoFraming);
            currentEffect = effect;
            currentImageUrl = imageUrl;
            isAutoFramingEnabled = autoFraming;
            
            if (effect === 'image' && imageUrl && sourceVideo) {
                await updateBackgroundImage(imageUrl, sourceVideo.videoWidth, sourceVideo.videoHeight);
            }
            const processedStream = canvas.captureStream(30);
            return processedStream.getVideoTracks()[0];
        }

        stopProcessing(); // Clean up any existing loop

        currentTrackId = rawTrack.id;
        currentEffect = effect;
        currentImageUrl = imageUrl;

        const video = document.createElement("video");
        sourceVideo = video;
        video.autoplay = true;
        video.playsInline = true;
        video.muted = true;
        const sourceStream = new MediaStream([rawTrack]);
        video.srcObject = sourceStream;

        await video.play();

        if (effect === 'image' && imageUrl) {
            await updateBackgroundImage(imageUrl, video.videoWidth, video.videoHeight);
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        // Setup offscreen canvases
        if (!blurCanvas) blurCanvas = document.createElement("canvas");
        blurCanvas.width = Math.round(video.videoWidth / 8); 
        blurCanvas.height = Math.round(video.videoHeight / 8);
        blurCtx = blurCanvas.getContext("2d");

        if (!personCanvas) personCanvas = document.createElement("canvas");
        personCanvas.width = video.videoWidth;
        personCanvas.height = video.videoHeight;
        personCtx = personCanvas.getContext("2d");

        console.log(`[BackgroundBlur] Video dimensions: ${video.videoWidth}x${video.videoHeight}, mode: ${currentRunningMode}`);

        const draw = () => {
            if (!video || !canvas || !segmenter.value) {
                console.warn('[BackgroundBlur] draw() skipped: missing refs', { video: !!video, canvas: !!canvas, segmenter: !!segmenter.value });
                return;
            }
            
            // Handle dimension changes (e.g., orientation or camera switch)
            if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
                console.log(`[BackgroundBlur] Updating dimensions to ${video.videoWidth}x${video.videoHeight}`);
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                if (blurCanvas) {
                    blurCanvas.width = Math.round(video.videoWidth / 8);
                    blurCanvas.height = Math.round(video.videoHeight / 8);
                }
                if (personCanvas) {
                    personCanvas.width = video.videoWidth;
                    personCanvas.height = video.videoHeight;
                }
                if (currentEffect === 'image' && currentImageUrl) {
                    updateBackgroundImage(currentImageUrl, canvas.width, canvas.height);
                }
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
                     const alphaValue = Math.round(maskData[i] * 255);
                     const j = i * 4;
                     data[j] = 255;       // R
                     data[j + 1] = 255;   // G
                     data[j + 2] = 255;   // B
                     data[j + 3] = alphaValue; // A (Opacity = Person confidence)
                 }

                 // Convert ImageData to ImageBitmap for efficient canvas drawing
                 createImageBitmap(maskImageData).then(bmp => {
                     if (isAutoFramingEnabled) {
                         updateFraming(maskData, width, height);
                     }
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
                     const alpha = val > 0 ? 255 : 0; // 0 = background
                     
                     const j = i * 4;
                     data[j] = 255;       // R
                     data[j + 1] = 255;   // G
                     data[j + 2] = 255;   // B
                     data[j + 3] = alpha; // A
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
             if (!ctx || !canvas || !video || !blurCtx || !personCtx || !blurCanvas || !personCanvas) return;

             const w = canvas.width;
             const h = canvas.height;

             // Update smooth framing
             if (isAutoFramingEnabled) {
                 framing.centerX += (framing.targetCenterX - framing.centerX) * 0.05;
                 framing.centerY += (framing.targetCenterY - framing.centerY) * 0.05;
                 framing.zoom += (framing.targetZoom - framing.zoom) * 0.05;
             } else {
                 framing.centerX = 0.5;
                 framing.centerY = 0.5;
                 framing.zoom = 1.0;
             }

             const drawOptimized = (targetCtx: CanvasRenderingContext2D, source: CanvasImageSource, targetWidth: number, targetHeight: number) => {
                 targetCtx.imageSmoothingEnabled = true;
                 targetCtx.imageSmoothingQuality = 'high';
                 
                 if (isAutoFramingEnabled && framing.zoom > 1.0) {
                    const sw = w / framing.zoom;
                    const sh = h / framing.zoom;
                    const sx = (framing.centerX * w) - (sw / 2);
                    const sy = (framing.centerY * h) - (sh / 2);
                    
                    // Constrain source rect
                    const csx = Math.max(0, Math.min(w - sw, sx));
                    const csy = Math.max(0, Math.min(h - sh, sy));
                    
                    targetCtx.drawImage(source, csx, csy, sw, sh, 0, 0, targetWidth, targetHeight);
                 } else {
                    targetCtx.drawImage(source, 0, 0, targetWidth, targetHeight);
                 }
             };

             // 1. Prepare blurred background (on small canvas)
             blurCtx.filter = 'blur(6px)'; 
             drawOptimized(blurCtx, video, blurCanvas.width, blurCanvas.height);
             blurCtx.filter = 'none';
             
             // 2. Prepare person (on person canvas)
             personCtx.clearRect(0, 0, w, h);
             personCtx.save();
             personCtx.filter = 'blur(3px)'; 
             drawOptimized(personCtx, mask, w, h);
             personCtx.restore();
             
             personCtx.globalCompositeOperation = 'source-in';
             drawOptimized(personCtx, video, w, h);
             personCtx.globalCompositeOperation = 'source-over';

             // 3. Final composition on main canvas
             ctx.imageSmoothingEnabled = true;
             ctx.imageSmoothingQuality = 'high';
             ctx.clearRect(0, 0, w, h);

             if (currentEffect === 'image' && cachedBgCanvas) {
                ctx.drawImage(cachedBgCanvas, 0, 0, w, h);
             } else {
                ctx.drawImage(blurCanvas, 0, 0, w, h);
             }
             
             ctx.drawImage(personCanvas, 0, 0, w, h);
             
             if (shouldClose) {
                mask.close();
             }
             
            animationFrameId = requestAnimationFrame(draw);
        };

        const updateFraming = (maskData: Float32Array, width: number, height: number) => {
            let minX = width, minY = height, maxX = 0, maxY = 0;
            let found = false;

            // Sample mask to find person bounds
            const step = 4; // Faster sampling
            for (let y = 0; y < height; y += step) {
                for (let x = 0; x < width; x += step) {
                    const val = maskData[y * width + x];
                    if (val > 0.5) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                        found = true;
                    }
                }
            }

            if (found) {
                // Calculate target center and zoom
                const personWidth = (maxX - minX) / width;
                const personHeight = (maxY - minY) / height;
                
                framing.targetCenterX = (minX + maxX) / (2 * width);
                framing.targetCenterY = (minY + maxY) / (2 * height);
                
                // Target zoom to keep person at ~70% of frame height
                const desiredHeight = 0.7;
                const zoomFactor = desiredHeight / personHeight;
                framing.targetZoom = Math.max(1.0, Math.min(2.0, zoomFactor));
            } else {
                framing.targetCenterX = 0.5;
                framing.targetCenterY = 0.5;
                framing.targetZoom = 1.0;
            }
        };
        
        draw();

        const processedStream = canvas.captureStream(30); 
        const outputTrack = processedStream.getVideoTracks()[0];
        console.log('[BackgroundBlur] Returning canvas track:', outputTrack?.id, 'enabled:', outputTrack?.enabled);
        return outputTrack;
    }
    
    async function updateBackgroundImage(url: string, width: number, height: number) {
        if (!cachedBgCanvas) {
            cachedBgCanvas = document.createElement("canvas");
            cachedBgCtx = cachedBgCanvas.getContext("2d");
        }
        
        cachedBgCanvas.width = width;
        cachedBgCanvas.height = height;

        bgImage = new Image();
        bgImage.crossOrigin = "anonymous";
        bgImage.src = url;
        
        await new Promise((resolve) => {
            if (!bgImage) return resolve(null);
            bgImage.onload = () => {
                if (cachedBgCtx && bgImage) {
                    cachedBgCtx.drawImage(bgImage, 0, 0, width, height);
                }
                resolve(null);
            };
            bgImage.onerror = () => {
                console.error("[BackgroundBlur] Failed to load image:", url);
                resolve(null);
            };
        });
    }

    function stopProcessing() {
        console.log("[BackgroundBlur] Stopping processing");
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
        }
        if (sourceVideo) {
            sourceVideo.pause();
            sourceVideo.srcObject = null;
            sourceVideo = null;
        }
        currentTrackId = null;
    }

    function destroy() {
        stopProcessing();
        if (segmenter.value) {
            console.log("[BackgroundBlur] Closing segmenter and releasing GPU memory");
            segmenter.value.close();
            segmenter.value = null;
        }
        isLoaded.value = false;
        
        // Clear cached canvases
        canvas = null;
        blurCanvas = null;
        personCanvas = null;
        cachedBgCanvas = null;
        bgImage = null;
    }
    
    onUnmounted(() => {
        destroy();
    });

    return {
        loadModel,
        startVideoEffect,
        stopProcessing,
        destroy,
        isLoaded,
        isLoading,
        error
    };
}
