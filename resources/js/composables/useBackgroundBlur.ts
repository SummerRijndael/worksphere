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
    const isSupported = ref(true); // Default true, set to false if model completely fails
    const error = ref<string | null>(null);
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const cpuCores = navigator.hardwareConcurrency || 4;
    const isLowEnd = cpuCores < 4 || (isMobile && cpuCores < 8);


    // Canvas for processing
    let canvas: HTMLCanvasElement | null = null;
    let ctx: CanvasRenderingContext2D | null = null;
    let animationFrameId: number | null = null;
    let currentRunningMode: 'VIDEO' | 'IMAGE' = 'VIDEO';
    let processedStream: MediaStream | null = null;
    let outputTrack: MediaStreamTrack | null = null;
    
    // Offscreen canvases for performance
    let blurCanvas: HTMLCanvasElement | null = null;
    let blurCtx: CanvasRenderingContext2D | null = null;
    let personCanvas: HTMLCanvasElement | null = null;
    let personCtx: CanvasRenderingContext2D | null = null;
    let segCanvas: HTMLCanvasElement | null = null;
    let segCtx: CanvasRenderingContext2D | null = null;
    let cachedBgCanvas: HTMLCanvasElement | null = null;
    let cachedBgCtx: CanvasRenderingContext2D | null = null;
    
    // Auto-framing state
    let framing = {
        centerX: 0.5,
        centerY: 0.5,
        zoom: 1.0,
        targetCenterX: 0.5,
        targetCenterY: 0.5,
        targetZoom: 1.0,
        deadZone: 0.15 // 15% Dead zone to prevent jitter
    };
    
    // Internal refs to keep tracks alive
    let sourceVideo: HTMLVideoElement | null = null;
    let bgImage: HTMLImageElement | null = null;
    let currentTrackId: string | null = null;
    let currentEffect: 'blur' | 'image' | 'none' = 'none';
    let currentImageUrl: string | undefined = undefined;
    let isAutoFramingEnabled = false;
    let useChromaKey = false;
    let chromaKeyColor = { r: 0, g: 255, b: 0 };
    let chromaKeyThreshold = 0.12;

    // Mask state for reuse
    let maskImageData: ImageData | null = null;
    let maskImageDataWidth = 0;
    let maskImageDataHeight = 0;

    // Adaptive quality: track actual FPS to decide if we should downgrade model
    const FPS_WINDOW = 30; // sliding window of last N frame timestamps
    const FPS_DOWNGRADE_THRESHOLD = 20; // fps below this = struggling (was 15)
    const FPS_DOWNGRADE_WINDOW_MS = 2000; // must sustain low fps for this long (was 5000)
    let fpsFrameTimes: number[] = [];
    let lowFpsStartTime: number | null = null;
    let hasDowngraded = false;
    let isDowngrading = false;
    
    // Hardware Support Verification
    function checkWebGLSupport(): boolean {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl2') as WebGL2RenderingContext | null;
            if (!gl) {
                console.warn("[BackgroundBlur] WebGL2 not supported.");
                return false;
            }
            
            // MediaPipe GPU delegate strictly requires rendering to float32 or float16 textures
            const extColorBufferFloat = gl.getExtension('EXT_color_buffer_float');
            const extColorBufferHalfFloat = gl.getExtension('EXT_color_buffer_half_float');
            
            if (!extColorBufferFloat && !extColorBufferHalfFloat) {
                console.warn("[BackgroundBlur] GPU lacks EXT_color_buffer_float / half_float. Forcing CPU mode.");
                return false;
            }
            
            return true;
        } catch (e) {
            console.warn("[BackgroundBlur] WebGL capability check failed:", e);
            return false;
        }
    }

    async function loadModel() {
        if (isLoaded.value || isLoading.value) return;
        isLoading.value = true;
        try {
             const vision = await FilesetResolver.forVisionTasks(
                "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@latest/wasm"
            );
            
            // Device-aware model selection: Landscape (Lite) for mobile/weak CPUs, Full for Desktop
            const modelPath = isLowEnd 
                ? "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter_landscape/float16/latest/selfie_segmenter_landscape.tflite"
                : "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite";

            const gpuSupported = checkWebGLSupport();
            
            if (gpuSupported) {
                segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                    baseOptions: {
                        modelAssetPath: modelPath,
                        delegate: "GPU",
                    },
                    runningMode: "VIDEO" as const,
                    outputCategoryMask: false, 
                    outputConfidenceMasks: true,
                });
                isLoaded.value = true;
                currentRunningMode = 'VIDEO';
                console.log("[BackgroundBlur] Model loaded (GPU)");
            } else {
                throw new Error("GPU Requirements unmet. Throwing to CPU fallback explicitly.");
            }
        } catch(e) {
            console.warn("GPU setup bypassed or failed, trying CPU", e);
             try {
                    const vision = await FilesetResolver.forVisionTasks(
                        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@latest/wasm"
                    );
                    // Fallback to CPU model for devices lacking float16 WebGL rendering
                    const modelPath = isLowEnd 
                        ? "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter_landscape/float16/latest/selfie_segmenter_landscape.tflite"
                        : "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite";

                    segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                        baseOptions: {
                            modelAssetPath: modelPath,
                            delegate: "CPU",
                        },
                        runningMode: "VIDEO" as const,
                        outputCategoryMask: false, 
                        outputConfidenceMasks: true,
                    });
                     isLoaded.value = true;
                     currentRunningMode = 'VIDEO';
                     console.log("[BackgroundBlur] Model loaded (CPU - Fallback)");
             } catch (retryError) {
                 isSupported.value = false;
                 error.value = "Failed to load blur model entirely. Device may be unsupported.";
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
        autoFraming: boolean = false,
        hasGreenScreen: boolean = false,
        greenScreenColor: string = '#00FF00',
        greenScreenThreshold: number = 0.12
    ): Promise<MediaStreamTrack> {
        isAutoFramingEnabled = autoFraming;
        useChromaKey = hasGreenScreen;
        chromaKeyThreshold = greenScreenThreshold;
        
        // Convert hex to RGB
        const hex = greenScreenColor.replace('#', '');
        chromaKeyColor = {
            r: parseInt(hex.substring(0, 2), 16),
            g: parseInt(hex.substring(2, 4), 16),
            b: parseInt(hex.substring(4, 6), 16)
        };

        if (useChromaKey) {
            console.log("[BackgroundBlur] Chroma Key Enabled:", greenScreenColor, "Threshold:", greenScreenThreshold);
        }

        if (!isLoaded.value && !useChromaKey) {
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
            if (!canvas) return rawTrack;
            
            if (outputTrack) {
                console.log("[BackgroundBlur] Reusing existing output track for updated effect");
                return outputTrack;
            }
            
            processedStream = canvas.captureStream(30);
            outputTrack = processedStream.getVideoTracks()[0];
            return outputTrack;
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

        if (!canvas) {
            canvas = document.createElement("canvas");
            ctx = canvas.getContext("2d");
        }
        
        // Resolution Strategy:
        // Output remains High Fidelity (720p) for recordings/presentation
        // Processing (Segmentation) happens at 480p to eliminate lag
        const outputWidth = video.videoWidth;
        const outputHeight = video.videoHeight;
        
        const MAX_PROCESSING_DIM = isLowEnd ? 360 : 480; 
        let procWidth = outputWidth;
        let procHeight = outputHeight;
        
        if (procWidth > MAX_PROCESSING_DIM || procHeight > MAX_PROCESSING_DIM) {
            const ratio = procWidth / procHeight;
            if (procWidth > procHeight) {
                 procWidth = MAX_PROCESSING_DIM;
                 procHeight = Math.round(MAX_PROCESSING_DIM / ratio);
            } else {
                 procHeight = MAX_PROCESSING_DIM;
                 procWidth = Math.round(MAX_PROCESSING_DIM * ratio);
            }
        }
        
        canvas.width = outputWidth;
        canvas.height = outputHeight;
        
        if (ctx) {
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'medium';
        }

        // Person Canvas (full resolution for composition)
        if (!personCanvas) personCanvas = document.createElement("canvas");
        personCanvas.width = outputWidth;
        personCanvas.height = outputHeight;
        personCtx = personCanvas.getContext("2d", { willReadFrequently: false });

        // Segmentation Canvas (internal downsampled source for segmenter)
        if (!segCanvas) segCanvas = document.createElement("canvas");
        segCanvas.width = procWidth;
        segCanvas.height = procHeight;
        // willReadFrequently is only helpful if we call getImageData/putImageData (ChromaKey)
        segCtx = segCanvas.getContext("2d", { willReadFrequently: useChromaKey });
        if (segCtx) {
            segCtx.imageSmoothingEnabled = true;
            segCtx.imageSmoothingQuality = 'medium';
        }

        // Setup offscreen canvases
        if (!blurCanvas) blurCanvas = document.createElement("canvas");
        const blurDownsample = isMobile ? 12 : 8;
        blurCanvas.width = Math.round(outputWidth / blurDownsample); 
        blurCanvas.height = Math.round(outputHeight / blurDownsample);
        blurCtx = blurCanvas.getContext("2d", { alpha: false, willReadFrequently: false });
        if (blurCtx) {
            blurCtx.imageSmoothingEnabled = true;
            blurCtx.imageSmoothingQuality = 'medium';
        }

        console.log(`[BackgroundBlur] Quality: Output ${outputWidth}x${outputHeight}, Processing ${procWidth}x${procHeight}, Mode: ${currentRunningMode}`);


        let lastFrameTime = 0;
        const targetFps = isMobile ? 15 : 30;
        const frameInterval = 1000 / targetFps;

        const draw = (timestamp: number) => {
            if (!video || !canvas || !segmenter.value) {
                console.warn('[BackgroundBlur] draw() skipped: missing refs', { video: !!video, canvas: !!canvas, segmenter: !!segmenter.value });
                return;
            }

            // Throttling
            const elapsed = timestamp - lastFrameTime;
            if (elapsed < frameInterval) {
                animationFrameId = requestAnimationFrame(draw);
                return;
            }
            lastFrameTime = timestamp - (elapsed % frameInterval);
            
            // Handle dimension changes (e.g., orientation or camera switch)
            if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
                // If native video size changes, we might want to re-evaluate our capping logic.
                // For simplicity, we just obey the new video size IF it's smaller, or re-cap it.
                // But re-running the full calc inside draw loop might be heavy. 
                // Let's just update to match video for now, or keep the capped canvas if we want to force scaler?
                // Actually, standard behavior is to adapt. Let's re-run capping logic if needed.
                
                let newTargetWidth = video.videoWidth;
                let newTargetHeight = video.videoHeight;
                
                if (isMobile) {
                const MAX_DIMENSION = isLowEnd ? 360 : 720;
                if (newTargetWidth > MAX_DIMENSION || newTargetHeight > MAX_DIMENSION) {
                    const ratio = newTargetWidth / newTargetHeight;
                    if (newTargetWidth > newTargetHeight) {
                         newTargetWidth = MAX_DIMENSION;
                         newTargetHeight = Math.round(MAX_DIMENSION / ratio);
                    } else {
                         newTargetHeight = MAX_DIMENSION;
                         newTargetWidth = Math.round(MAX_DIMENSION * ratio);
                    }
                }
                }
                
                if (canvas.width !== newTargetWidth || canvas.height !== newTargetHeight) {
                    console.log(`[BackgroundBlur] Updating dimensions to ${newTargetWidth}x${newTargetHeight}`);
                    canvas.width = newTargetWidth;
                    canvas.height = newTargetHeight;
                    
                    if (ctx) {
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';
                    }
                    
                    if (blurCanvas) {
                        const blurDownsample = isMobile ? 12 : 8;
                        blurCanvas.width = Math.round(newTargetWidth / blurDownsample);
                        blurCanvas.height = Math.round(newTargetHeight / blurDownsample);
                        if (blurCtx) {
                            blurCtx.imageSmoothingEnabled = true;
                            blurCtx.imageSmoothingQuality = 'high';
                        }
                    }
    
                    if (personCanvas) {
                        personCanvas.width = newTargetWidth;
                        personCanvas.height = newTargetHeight;
                    }
                    if (segCanvas) {
                        // Re-apply capping logic for segCanvas
                        const MAX_DIM = isLowEnd ? 360 : 480;
                        let sw = newTargetWidth;
                        let sh = newTargetHeight;
                        if (sw > MAX_DIM || sh > MAX_DIM) {
                            const r = sw / sh;
                            if (sw > sh) { sw = MAX_DIM; sh = Math.round(MAX_DIM / r); }
                            else { sh = MAX_DIM; sw = Math.round(MAX_DIM * r); }
                        }
                        segCanvas.width = sw;
                        segCanvas.height = sh;
                    }
                    if (currentEffect === 'image' && currentImageUrl) {
                        updateBackgroundImage(currentImageUrl, canvas.width, canvas.height);
                    }
                }
            }

            // Check if video is still active/playing
            if (video.paused || video.ended) {
                console.warn('[BackgroundBlur] draw() skipped: video paused or ended');
                return;
            }
            
            try {
                // ── Adaptive FPS tracking ─────────────────────────────────────
                const now = performance.now();
                fpsFrameTimes.push(now);
                if (fpsFrameTimes.length > FPS_WINDOW) fpsFrameTimes.shift();

                if (fpsFrameTimes.length >= FPS_WINDOW && !hasDowngraded && !isDowngrading) {
                    const windowMs = fpsFrameTimes[fpsFrameTimes.length - 1] - fpsFrameTimes[0];
                    const actualFps = (FPS_WINDOW - 1) / (windowMs / 1000);

                    if (actualFps < FPS_DOWNGRADE_THRESHOLD) {
                        if (!lowFpsStartTime) lowFpsStartTime = now;
                        else if (now - lowFpsStartTime > FPS_DOWNGRADE_WINDOW_MS) {
                            console.warn(`[BackgroundBlur] Sustained low FPS (${actualFps.toFixed(1)}fps). Downgrading to lite model.`);
                            downgradeToLiteModel();
                        }
                    } else {
                        lowFpsStartTime = null; // reset if fps recovers
                    }
                }
                // ─────────────────────────────────────────────────────────────

                if (useChromaKey) {
                    // Manual Chroma Key Path
                    const maskData = processChromaKey(video);
                    renderChromaKeyResult(maskData, segCanvas.width, segCanvas.height);
                } else if (segmenter.value) {
                    // Draw video to segmentation canvas (downsampled)
                    if (segCtx && segCanvas) {
                        segCtx.drawImage(video, 0, 0, segCanvas.width, segCanvas.height);
                        
                        if (currentRunningMode === 'IMAGE') {
                            const result = segmenter.value.segment(segCanvas);
                            renderResult(result);
                        } else {
                            const startTime = performance.now();
                            segmenter.value.segmentForVideo(segCanvas, startTime, renderResult);
                        }
                    }
                } else {
                    // No segmenter and no chroma key? Just draw raw
                    if (ctx) ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    animationFrameId = requestAnimationFrame(draw);
                }
            } catch (e: any) {
                console.error("Segmentation error:", e && e.message ? e.message : String(e), e && e.stack ? e.stack : "");
                // Resilient fallback: Always show at least the raw video
                if (ctx && canvas && video) {
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                }
                animationFrameId = requestAnimationFrame(draw);
            }
        };

        const processChromaKey = (video: HTMLVideoElement): Float32Array => {
            if (!segCtx || !segCanvas) return new Float32Array(0);
            
            // Draw video to segCanvas to get pixel data
            segCtx.drawImage(video, 0, 0, segCanvas.width, segCanvas.height);
            const imageData = segCtx.getImageData(0, 0, segCanvas.width, segCanvas.height);
            const data = imageData.data;
            const mask = new Float32Array(data.length / 4);
            
            const targetR = chromaKeyColor.r;
            const targetG = chromaKeyColor.g;
            const targetB = chromaKeyColor.b;
            const thresholdSq = Math.pow(chromaKeyThreshold * 255, 2) * 3; // 3D distance threshold

            for (let i = 0; i < data.length; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];
                
                // Euclidean distance in RGB space
                const distSq = Math.pow(r - targetR, 2) + Math.pow(g - targetG, 2) + Math.pow(b - targetB, 2);
                
                // If distance is large, it's a person (not the key color)
                mask[i / 4] = distSq > thresholdSq ? 1.0 : 0.0;
            }
            
            return mask;
        };

        function fastBoxBlur(data: Float32Array, width: number, height: number, radius: number) {
            if (radius < 1) return;
            const temp = new Float32Array(data.length);
            
            // Horizontal
            for (let y = 0; y < height; y++) {
                let sum = 0;
                for (let i = -radius; i <= radius; i++) {
                    const x = Math.min(Math.max(i, 0), width - 1);
                    sum += data[y * width + x];
                }
                for (let x = 0; x < width; x++) {
                    temp[y * width + x] = sum / (2 * radius + 1);
                    const nextX = Math.min(x + radius + 1, width - 1);
                    const prevX = Math.max(x - radius, 0);
                    sum += data[y * width + nextX] - data[y * width + prevX];
                }
            }
            // Vertical
            for (let x = 0; x < width; x++) {
                let sum = 0;
                for (let i = -radius; i <= radius; i++) {
                    const y = Math.min(Math.max(i, 0), height - 1);
                    sum += temp[y * width + x];
                }
                for (let y = 0; y < height; y++) {
                    data[y * width + x] = sum / (2 * radius + 1);
                    const nextY = Math.min(y + radius + 1, height - 1);
                    const prevY = Math.max(y - radius, 0);
                    sum += temp[nextY * width + x] - temp[prevY * width + x];
                }
            }
        }

        const renderChromaKeyResult = (maskData: Float32Array, width: number, height: number) => {
            fastBoxBlur(maskData, width, height, isMobile ? 2 : 3);
            
            if (!maskImageData || maskImageDataWidth !== width || maskImageDataHeight !== height) {
                maskImageData = new ImageData(width, height);
                maskImageDataWidth = width;
                maskImageDataHeight = height;
            }
            
            const data = maskImageData.data;
            for (let i = 0; i < maskData.length; i++) {
                const alpha = Math.round(maskData[i] * 255);
                const j = i * 4;
                data[j] = 255;
                data[j + 1] = 255;
                data[j + 2] = 255;
                data[j + 3] = alpha;
            }

            if (isAutoFramingEnabled) {
                updateFraming(maskData, width, height);
            }

            createImageBitmap(maskImageData).then(bmp => {
                drawComposition(bmp, true);
            }).catch(e => {
                console.error("[BackgroundBlur] ChromaKey createImageBitmap failed", e);
                if (ctx && canvas) ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                animationFrameId = requestAnimationFrame(draw);
            });
        };

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
                 let maskData: Float32Array;

                 try {
                     maskData = mask.getAsFloat32Array();
                 } catch (err: any) {
                     // WebGL ReadPixels crash (Format/Type incompatible) on Firefox/Chromium
                     console.warn("[BackgroundBlur] Hardware GPU Mask extraction failed. Forcing CPU downgrade.", err);
                     if (!isDowngrading) downgradeToLiteModel();
                     if (ctx && canvas && video) ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                     animationFrameId = requestAnimationFrame(draw);
                     return;
                 }

                 // Software blur on the float mask data directly for superior soft-edge rendering
                 // Skip manual blur on low-end devices to save CPU; MediaPipe is usually enough.
                 if (!isLowEnd && !hasDowngraded) {
                     fastBoxBlur(maskData, width, height, isMobile ? 2 : 3);
                 }

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
                 if (isAutoFramingEnabled) {
                     updateFraming(maskData, width, height);
                 }
                 createImageBitmap(maskImageData).then(bmp => {
                     drawComposition(bmp, true);
                 }).catch(e => {
                     console.error("[BackgroundBlur] ConfidenceMask createImageBitmap failed", e);
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
                 let maskData: Uint8Array;
                 try {
                     maskData = mask.getAsUint8Array();
                 } catch (err: any) {
                     console.warn("[BackgroundBlur] Hardware GPU CategoryMask extraction failed. Forcing CPU downgrade.", err);
                     if (!isDowngrading) downgradeToLiteModel();
                     if (ctx && canvas && video) ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                     animationFrameId = requestAnimationFrame(draw);
                     return;
                 }
                 
                 const floatMaskData = new Float32Array(maskData.length);
                 for (let i = 0; i < maskData.length; i++) {
                     floatMaskData[i] = maskData[i] > 0 ? 1.0 : 0.0;
                 }
                 
                 // Apply software blur for Category mask
                 fastBoxBlur(floatMaskData, width, height, isMobile ? 2 : 3);

                 if (!maskImageData || maskImageDataWidth !== width || maskImageDataHeight !== height) {
                     maskImageData = new ImageData(width, height);
                     maskImageDataWidth = width;
                     maskImageDataHeight = height;
                 }
                 const data = maskImageData.data;
                 for (let i = 0; i < floatMaskData.length; i++) {
                     const alpha = Math.round(floatMaskData[i] * 255);
                     
                     const j = i * 4;
                     data[j] = 255;       // R
                     data[j + 1] = 255;   // G
                     data[j + 2] = 255;   // B
                     data[j + 3] = alpha; // A
                 }
                 
                 if (isAutoFramingEnabled) {
                     updateFraming(floatMaskData, width, height);
                 }

                 createImageBitmap(maskImageData).then(bmp => {
                     drawComposition(bmp, true);
                 }).catch(e => {
                     console.error("[BackgroundBlur] CategoryMask createImageBitmap failed", e);
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
             if (!ctx || !canvas || !video || !blurCtx || !personCtx || !blurCanvas || !personCanvas) {
                 if (shouldClose && mask instanceof ImageBitmap) mask.close();
                 // Even if we can't draw the composition, we MUST trigger the next frame
                 animationFrameId = requestAnimationFrame(draw);
                 return;
             }

             const w = canvas.width;
             const h = canvas.height;

              // Update smooth framing with Cinematic Dead Zone
              if (isAutoFramingEnabled) {
                  const lerpFactor = 0.05; // Slower, more cinematic movement
                  
                  // Only update targets if movement exceeds dead zone (15%)
                  const dx = Math.abs(framing.targetCenterX - framing.centerX);
                  const dy = Math.abs(framing.targetCenterY - framing.centerY);
                  
                  if (dx > framing.deadZone || dy > framing.deadZone) {
                      framing.centerX += (framing.targetCenterX - framing.centerX) * lerpFactor;
                      framing.centerY += (framing.targetCenterY - framing.centerY) * lerpFactor;
                  }
                  
                  framing.zoom += (framing.targetZoom - framing.zoom) * lerpFactor;
              } else {
                 framing.centerX = 0.5;
                 framing.centerY = 0.5;
                 framing.zoom = 1.0;
                 framing.targetCenterX = 0.5;
                 framing.targetCenterY = 0.5;
                 framing.targetZoom = 1.0;
             }

              const drawOptimized = (targetCtx: CanvasRenderingContext2D, source: CanvasImageSource, targetWidth: number, targetHeight: number) => {
                  const sourceW = (source as any).width || (source as HTMLVideoElement).videoWidth;
                  const sourceH = (source as any).height || (source as HTMLVideoElement).videoHeight;

                  if (isAutoFramingEnabled && framing.zoom > 1.0) {
                     const sw_zoom = sourceW / framing.zoom;
                     const sh_zoom = sourceH / framing.zoom;
                     const sx_zoom = (framing.centerX * sourceW) - (sw_zoom / 2);
                     const sy_zoom = (framing.centerY * sourceH) - (sh_zoom / 2);
                     
                     // Constrain source rect
                     const csx = Math.max(0, Math.min(sourceW - sw_zoom, sx_zoom));
                     const csy = Math.max(0, Math.min(sourceH - sh_zoom, sy_zoom));
                     
                     targetCtx.drawImage(source, csx, csy, sw_zoom, sh_zoom, 0, 0, targetWidth, targetHeight);
                  } else {
                     targetCtx.drawImage(source, 0, 0, targetWidth, targetHeight);
                  }
              };

             // 1. Prepare blurred background (on small canvas)
             if (currentEffect === 'blur') {
                 blurCtx.filter = isMobile ? 'blur(8px)' : 'blur(16px)'; // Strong Gaussian-like distribution
             } else {
                 blurCtx.filter = 'none';
             }
             drawOptimized(blurCtx, video, blurCanvas.width, blurCanvas.height);
             blurCtx.filter = 'none';

              // 2. Prepare person with mask feathering
              personCtx.setTransform(1, 0, 0, 1, 0, 0);
              personCtx.clearRect(0, 0, w, h);
              
              // Draw mask - always stretched to full output resolution
              // We avoid drawOptimized for the mask to ensure it always matches output geometry perfectly
              personCtx.drawImage(mask, 0, 0, (mask as any).width, (mask as any).height, 0, 0, w, h);
              
              personCtx.globalCompositeOperation = 'source-in';
              // Draw video - applied with framing (if enabled)
              drawOptimized(personCtx, video, w, h);
              personCtx.globalCompositeOperation = 'source-over';

             // 3. Final composition on main canvas
             ctx.clearRect(0, 0, w, h);

              if (currentEffect === 'image' && cachedBgCanvas) {
                 drawOptimized(ctx, cachedBgCanvas, w, h);
              } else {
                 ctx.drawImage(blurCanvas, 0, 0, w, h);
             }
             
             ctx.drawImage(personCanvas, 0, 0, w, h);
             
             if (shouldClose && mask instanceof ImageBitmap) {
                mask.close();
             }
             
            animationFrameId = requestAnimationFrame(draw);
        };

        const updateFraming = (maskData: Float32Array, width: number, height: number) => {
            if (!isAutoFramingEnabled) return;
            
            let minX = width, minY = height, maxX = 0, maxY = 0;
            let found = false;
            let sumX = 0, sumY = 0, count = 0;

            // Sample mask to find person bounds
            // Ignore outer 10% edges to filter sensor/segmentation noise (fixes "zoomed to corner" bug)
            const marginX = Math.floor(width * 0.1);
            const marginY = Math.floor(height * 0.1);
            const step = 4; 
            
            for (let y = marginY; y < height - marginY; y += step) {
                for (let x = marginX; x < width - marginX; x += step) {
                    const val = maskData[y * width + x];
                    if (val > 0.6) { // Higher threshold for framing stability
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                        sumX += x;
                        sumY += y;
                        count++;
                        found = true;
                    }
                }
            }

            if (found && count > 50) { // Require minimum mass to move "camera"
                const personHeight = (maxY - minY) / height;
                const centroidX = sumX / (count * width);
                const centroidY = sumY / (count * height);
                
                framing.targetCenterX = centroidX;
                framing.targetCenterY = centroidY;
                
                // Target zoom to keep person at ~60% of frame height
                const desiredHeight = 0.6;
                const zoomFactor = desiredHeight / Math.max(0.2, personHeight);
                framing.targetZoom = Math.max(1.0, Math.min(2.0, zoomFactor));
            } else {
                framing.targetCenterX = 0.5;
                framing.targetCenterY = 0.5;
                framing.targetZoom = 1.0;
            }
        };
        
        // Start the loop with timestamp
        animationFrameId = requestAnimationFrame(draw);

        // Throttle FPS on mobile to prevent overheating/freezing
        const captureFps = isMobile ? 15 : 30;
        processedStream = canvas.captureStream(captureFps); 
        outputTrack = processedStream.getVideoTracks()[0];
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
        processedStream = null;
        outputTrack = null;
    }

    /**
     * Hot-swap to the lite (landscape) model without stopping the stream.
     * The draw loop will continue rendering raw video while the new model loads.
     */
    async function downgradeToLiteModel() {
        if (isDowngrading || hasDowngraded) return;
        isDowngrading = true;
        console.log("[BackgroundBlur] Downgrading to lite model...");

        try {
            // Close the heavy model
            if (segmenter.value) {
                segmenter.value.close();
                segmenter.value = null;
                isLoaded.value = false;
            }

            const vision = await FilesetResolver.forVisionTasks(
                "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@latest/wasm"
            );

            const liteModel = "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter_landscape/float16/latest/selfie_segmenter_landscape.tflite";

            // Try GPU first, fall back to CPU
            try {
                segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                    baseOptions: { modelAssetPath: liteModel, delegate: "GPU" },
                    runningMode: "VIDEO" as const,
                    outputCategoryMask: false,
                    outputConfidenceMasks: true,
                });
            } catch {
                segmenter.value = await ImageSegmenter.createFromOptions(vision, {
                    baseOptions: { modelAssetPath: liteModel, delegate: "CPU" },
                    runningMode: "VIDEO" as const,
                    outputCategoryMask: false,
                    outputConfidenceMasks: true,
                });
            }

            isLoaded.value = true;
            hasDowngraded = true;
            fpsFrameTimes = [];
            lowFpsStartTime = null;
            console.log("[BackgroundBlur] Lite model loaded. Resuming effects.");
        } catch (e) {
            console.error("[BackgroundBlur] Failed to load lite model:", e);
            error.value = "Your device is struggling to run video effects.";
        } finally {
            isDowngrading = false;
        }
    }

    function destroy() {
        stopProcessing();
        if (segmenter.value) {
            console.log("[BackgroundBlur] Closing segmenter and releasing GPU memory");
            segmenter.value.close();
            segmenter.value = null;
        }
        isLoaded.value = false;
        
        if (personCanvas) personCanvas = null;
        if (personCtx) personCtx = null;
        if (segCanvas) segCanvas = null;
        if (segCtx) segCtx = null;
        if (blurCanvas) blurCanvas = null;
        if (blurCtx) blurCtx = null;
        if (cachedBgCanvas) cachedBgCanvas = null;
        if (cachedBgCtx) cachedBgCtx = null;
        
        hasDowngraded = false;
        isDowngrading = false;
    }
    
    onUnmounted(() => {
        destroy();
    });

    async function autoDetectGreenScreenColor(videoTrack: MediaStreamTrack): Promise<string> {
        const video = document.createElement('video');
        video.srcObject = new MediaStream([videoTrack]);
        video.muted = true;
        await video.play();

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        if (!ctx) return '#00FF00';

        ctx.drawImage(video, 0, 0);
        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height).data;

        // Sample corners (often where the green screen is most visible)
        const samples = [
            getPixel(imageData, 10, 10, canvas.width),
            getPixel(imageData, canvas.width - 10, 10, canvas.width),
            getPixel(imageData, 10, canvas.height - 10, canvas.width),
            getPixel(imageData, canvas.width - 10, canvas.height - 10, canvas.width),
        ];

        // Pick the most common or average "greenish" color? 
        // For simplicity, let's just average the corners.
        const avg = samples.reduce((acc, curr) => ({
            r: acc.r + curr.r / 4,
            g: acc.g + curr.g / 4,
            b: acc.b + curr.b / 4
        }), { r: 0, g: 0, b: 0 });

        video.pause();
        video.srcObject = null;

        const toHex = (c: number) => Math.round(c).toString(16).padStart(2, '0');
        return `#${toHex(avg.r)}${toHex(avg.g)}${toHex(avg.b)}`.toUpperCase();
    }

    function getPixel(data: Uint8ClampedArray, x: number, y: number, width: number) {
        const i = (y * width + x) * 4;
        return { r: data[i], g: data[i + 1], b: data[i + 2] };
    }

    return {
        loadModel,
        startVideoEffect,
        stopProcessing,
        destroy,
        autoDetectGreenScreenColor,
        isLoaded,
        isLoading,
        isSupported,
        error
    };
}
