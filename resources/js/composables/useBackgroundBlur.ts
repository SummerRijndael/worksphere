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
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);


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
                                "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite",
                            delegate: "CPU",
                        },
                        runningMode: "VIDEO" as const,
                        outputCategoryMask: false, 
                        outputConfidenceMasks: true,
                    });
                     isLoaded.value = true;
                     currentRunningMode = 'VIDEO';
                     console.log("[BackgroundBlur] Model loaded (CPU - Lightweight)");
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

        if (!canvas) {
            canvas = document.createElement("canvas");
            ctx = canvas.getContext("2d");
        }
        
        // Resolution Capping for Mobile (S10 fix)
        // Limit max dimension to 360p to reduce segmentation load further
        let targetWidth = video.videoWidth;
        let targetHeight = video.videoHeight;
        
        if (isMobile) {
            const MAX_DIMENSION = 360; // Reduced from 480 for better stability
            if (targetWidth > MAX_DIMENSION || targetHeight > MAX_DIMENSION) {
                const ratio = targetWidth / targetHeight;
                if (targetWidth > targetHeight) {
                     targetWidth = MAX_DIMENSION;
                     targetHeight = Math.round(MAX_DIMENSION / ratio);
                } else {
                     targetHeight = MAX_DIMENSION;
                     targetWidth = Math.round(MAX_DIMENSION * ratio);
                }
            }
        }
        
        canvas.width = targetWidth;
        canvas.height = targetHeight;

        
        // Setup offscreen canvases
        if (!blurCanvas) blurCanvas = document.createElement("canvas");
        const blurDownsample = isMobile ? 12 : 8;
        // Blur canvas is even smaller for performance
        blurCanvas.width = Math.round(targetWidth / blurDownsample); 
        blurCanvas.height = Math.round(targetHeight / blurDownsample);
        blurCtx = blurCanvas.getContext("2d");


        if (!personCanvas) personCanvas = document.createElement("canvas");
        personCanvas.width = targetWidth;
        personCanvas.height = targetHeight;
        personCtx = personCanvas.getContext("2d");

        console.log(`[BackgroundBlur] Processing dimensions: ${targetWidth}x${targetHeight} (Source: ${video.videoWidth}x${video.videoHeight}), mode: ${currentRunningMode}`);


        let lastFrameTime = 0;
        let lastSuccessfulFrameTime = performance.now();
        let isAutoDowngraded = false;
        
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
                    const MAX_DIMENSION = 360;
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
                    
                    if (blurCanvas) {
                        const blurDownsample = isMobile ? 12 : 8;
                        blurCanvas.width = Math.round(newTargetWidth / blurDownsample);
                        blurCanvas.height = Math.round(newTargetHeight / blurDownsample);
                    }
    
                    if (personCanvas) {
                        personCanvas.width = newTargetWidth;
                        personCanvas.height = newTargetHeight;
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
                // Watchdog: If we haven't had a successful frame in 3 seconds, or we are consistently slow, fallback.
                const timeSinceSuccess = performance.now() - lastSuccessfulFrameTime;
                if (timeSinceSuccess > 3000 && !isAutoDowngraded) {
                    console.error("[BackgroundBlur] Watchdog Triggered: Video effect unsupported on this hardware/browser combination.");
                    isAutoDowngraded = true;
                    error.value = "Your device is struggling to run video effects. We've disabled them to keep your video running smoothly.";
                }

                if (isAutoDowngraded) {
                    // Just draw raw video
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    animationFrameId = requestAnimationFrame(draw);
                    return;
                }

                if (currentRunningMode === 'IMAGE') {
                    const result = segmenter.value.segment(video);
                    renderResult(result);
                } else {
                    const startTime = performance.now();
                    segmenter.value.segmentForVideo(video, startTime, renderResult);
                }
            } catch (e) {
                console.error("Segmentation error:", e);
                // Resilient fallback: Always show at least the raw video
                if (ctx && canvas && video) {
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                }
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
                 lastSuccessfulFrameTime = performance.now();
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
                 if (isAutoFramingEnabled) {
                     updateFraming(maskData, width, height);
                 }
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
                     const alpha = val > 0 ? 255 : 0; // 0 = background
                     
                     const j = i * 4;
                     data[j] = 255;       // R
                     data[j + 1] = 255;   // G
                     data[j + 2] = 255;   // B
                     data[j + 3] = alpha; // A
                 }
                 
                 if (isAutoFramingEnabled) {
                     // Convert Uint8Array to Float32Array for updateFraming
                     const floatMaskData = new Float32Array(maskData.length);
                     for (let i = 0; i < maskData.length; i++) {
                         floatMaskData[i] = maskData[i] > 0 ? 1.0 : 0.0;
                     }
                     updateFraming(floatMaskData, width, height);
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
                  const lerpFactor = 0.08; // Slightly faster but still smooth
                  framing.centerX += (framing.targetCenterX - framing.centerX) * lerpFactor;
                  framing.centerY += (framing.targetCenterY - framing.centerY) * lerpFactor;
                  framing.zoom += (framing.targetZoom - framing.zoom) * lerpFactor;
              } else {
                 framing.centerX = 0.5;
                 framing.centerY = 0.5;
                 framing.zoom = 1.0;
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
             blurCtx.filter = 'blur(4px)'; // Reduced from 6px
             drawOptimized(blurCtx, video, blurCanvas.width, blurCanvas.height);
             blurCtx.filter = 'none';
             
             // 2. Prepare person (on person canvas)
             personCtx.clearRect(0, 0, w, h);
             personCtx.save();
             personCtx.filter = 'blur(2px)'; // Reduced from 3px
             drawOptimized(personCtx, mask, w, h);
             personCtx.restore();
             
             personCtx.globalCompositeOperation = 'source-in';
             drawOptimized(personCtx, video, w, h);
             personCtx.globalCompositeOperation = 'source-over';

             // 3. Final composition on main canvas
             ctx.imageSmoothingEnabled = true;
             ctx.imageSmoothingQuality = isMobile ? 'low' : 'medium'; 
             ctx.clearRect(0, 0, w, h);

              if (currentEffect === 'image' && cachedBgCanvas) {
                 drawOptimized(ctx, cachedBgCanvas, w, h);
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
                // Target zoom to keep person at ~60% of frame height (better balance)
                 const desiredHeight = 0.6;
                 const zoomFactor = desiredHeight / personHeight;
                 framing.targetZoom = Math.max(1.0, Math.min(2.5, zoomFactor));

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
        const processedStream = canvas.captureStream(captureFps); 
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
