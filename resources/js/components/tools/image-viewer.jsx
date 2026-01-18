/**
 * Enhanced Media Viewer
 * Supports both images and videos with keyboard controls
 */
export function initMediaViewer() {
    // Check if already initialized to prevent double listeners
    if (window.mediaViewerInitialized) return;

    let mv = {
        media: [],
        index: 0,
        viewer: document.getElementById("media-viewer"),
        imgEl: document.getElementById("mv-image"),
        videoEl: document.getElementById("mv-video"),
        stageEl: document.getElementById("mv-stage"),
        counterEl: document.getElementById("mv-counter"),
        thumbsEl: document.getElementById("mv-thumbs"),
        prevBtn: document.getElementById("mv-prev"),
        nextBtn: document.getElementById("mv-next"),
        downloadBtn: document.getElementById("mv-download"),
        deleteBtn: document.getElementById("mv-delete"),
        playPauseBtn: document.getElementById("mv-play-pause"),
        infoBtn: document.getElementById("mv-info"),
        infoPanel: document.getElementById("mv-info-panel"),
        infoContent: document.getElementById("mv-info-content"),
        infoCloseBtn: document.getElementById("mv-info-close"),

        // Swipe vars
        touchStartX: 0,
        touchEndX: 0,
        minSwipeDistance: 50,

        open(media, index = 0) {
            this.media = media.map((item) =>
                typeof item === "string"
                    ? { src: item, download: item, id: null, type: this.detectMediaType(item) }
                    : {
                          src: item.src,
                          download: item.download || item.src,
                          id: item.id ?? null,
                          type: item.type || this.detectMediaType(item.src),
                          mimeType: item.mimeType || '',
                          canDelete:
                              typeof item.canDelete !== "undefined"
                                  ? !!item.canDelete
                                  : !!item.id,
                      },
            );
            this.index = index;
            this.renderThumbs();
            this.update(true);

            this.viewer.classList.remove("hidden");
            requestAnimationFrame(() => this.viewer.classList.add("visible"));
            document.body.style.overflow = "hidden";
        },

        close() {
            // Pause video if playing
            if (this.videoEl && !this.videoEl.paused) {
                this.videoEl.pause();
            }

            // Close info panel if open
            this.closeInfo();

            this.viewer.classList.remove("visible");
            setTimeout(() => {
                this.viewer.classList.add("hidden");
                this.imgEl.src = "";
                this.videoEl.src = "";
                this.videoEl.load(); // Reset video element
            }, 300);
            document.body.style.overflow = "auto";
        },

        next() {
            if (this.index < this.media.length - 1) {
                this.index++;
                this.update();
            }
        },

        prev() {
            if (this.index > 0) {
                this.index--;
                this.update();
            }
        },

        detectMediaType(src) {
            const videoExts = /\.(mp4|webm|ogg|mov|avi)$/i;
            const mimeType = src.mimeType || '';

            if (mimeType.startsWith('video/') || videoExts.test(src)) {
                return 'video';
            }
            return 'image';
        },

        update(immediate = false) {
            const current = this.media[this.index];
            const isVideo = current.type === 'video' || current.mimeType?.startsWith('video/');

            // Hide both elements first
            this.imgEl.style.display = "none";
            this.videoEl.style.display = "none";
            this.playPauseBtn.style.display = "none";

            if (!immediate) {
                this.stageEl.style.opacity = "0.6";
                this.stageEl.style.transform = "scale(0.98)";
            }

            setTimeout(
                () => {
                    if (isVideo) {
                        // Show video
                        this.videoEl.src = current.src;
                        this.videoEl.style.display = "block";
                        this.playPauseBtn.style.display = "flex";
                        this.videoEl.load();

                        // Auto-play video (muted for autoplay policy)
                        this.videoEl.muted = false;
                        this.videoEl.play().catch(() => {
                            // Autoplay failed, that's okay
                        });

                        this.stageEl.style.opacity = "1";
                        this.stageEl.style.transform = "scale(1)";
                    } else {
                        // Show image
                        this.imgEl.src = current.src;
                        this.imgEl.style.display = "block";
                        this.imgEl.onload = () => {
                            this.stageEl.style.opacity = "1";
                            this.stageEl.style.transform = "scale(1)";
                        };
                    }
                },
                immediate ? 0 : 150,
            );

            this.counterEl.innerText = `${this.index + 1} / ${this.media.length}`;

            this.prevBtn.style.opacity = this.index === 0 ? "0" : "1";
            this.prevBtn.style.pointerEvents =
                this.index === 0 ? "none" : "auto";

            this.nextBtn.style.opacity =
                this.index === this.media.length - 1 ? "0" : "1";
            this.nextBtn.style.pointerEvents =
                this.index === this.media.length - 1 ? "none" : "auto";

            if (current?.canDelete) {
                this.deleteBtn.style.display = "flex";
            } else {
                this.deleteBtn.style.display = "none";
            }

            [...this.thumbsEl.children].forEach((el, idx) => {
                el.classList.toggle("active", idx === this.index);
            });
            this.centerThumb();
        },

        renderThumbs() {
            this.thumbsEl.innerHTML = "";
            // Only show thumbs if there is more than 1 item
            if (this.media.length < 2) return;

            this.media.forEach((item, idx) => {
                let t = document.createElement("div");
                t.className = "mv-thumb";

                const isVideo = item.type === 'video' || item.mimeType?.startsWith('video/');

                if (isVideo) {
                    // Video thumbnail with play icon overlay
                    t.innerHTML = `
                        <video src="${item.src}" muted></video>
                        <div class="mv-thumb-play-icon">▶</div>
                    `;
                } else {
                    // Image thumbnail
                    t.innerHTML = `<img src="${item.src}">`;
                }

                t.onclick = () => {
                    this.index = idx;
                    this.update();
                };
                this.thumbsEl.appendChild(t);
            });
        },

        centerThumb() {
            let active = this.thumbsEl.children[this.index];
            if (!active) return;
            let wrapper = document.getElementById("mv-thumbs-wrapper");
            let scrollPos =
                active.offsetLeft -
                wrapper.offsetWidth / 2 +
                active.offsetWidth / 2;
            wrapper.scrollTo({
                left: scrollPos,
                behavior: "smooth",
            });
        },

        togglePlayPause() {
            if (!this.videoEl || this.videoEl.style.display === "none") return;

            if (this.videoEl.paused) {
                this.videoEl.play();
            } else {
                this.videoEl.pause();
            }
        },

        handleTouchStart(e) {
            this.touchStartX = e.changedTouches[0].screenX;
        },

        handleTouchEnd(e) {
            this.touchEndX = e.changedTouches[0].screenX;
            if (
                Math.abs(this.touchStartX - this.touchEndX) >
                this.minSwipeDistance
            ) {
                this.touchStartX - this.touchEndX > 0
                    ? this.next()
                    : this.prev();
            }
        },

        toggleInfo() {
            if (this.infoPanel.classList.contains('mv-info-panel--visible')) {
                this.closeInfo();
            } else {
                this.openInfo();
            }
        },

        openInfo() {
            this.populateInfo();
            this.infoPanel.classList.add('mv-info-panel--visible');
        },

        closeInfo() {
            this.infoPanel.classList.remove('mv-info-panel--visible');
        },

        populateInfo() {
            const current = this.media[this.index];
            if (!current) return;

            const isVideo = current.type === 'video';
            const isImage = current.type === 'image';

            let metadata = [];

            // Filename
            const filename = this.getFilename(current.src);
            metadata.push({ label: 'Filename', value: filename });

            // File Type
            if (current.mimeType) {
                metadata.push({ label: 'Type', value: current.mimeType });
            } else if (isVideo) {
                metadata.push({ label: 'Type', value: 'Video' });
            } else if (isImage) {
                metadata.push({ label: 'Type', value: 'Image' });
            }

            // Get dimensions and other metadata
            if (isImage && this.imgEl.naturalWidth) {
                metadata.push({
                    label: 'Dimensions',
                    value: `${this.imgEl.naturalWidth} × ${this.imgEl.naturalHeight}px`
                });

                const megapixels = (this.imgEl.naturalWidth * this.imgEl.naturalHeight) / 1000000;
                if (megapixels >= 0.1) {
                    metadata.push({
                        label: 'Resolution',
                        value: `${megapixels.toFixed(1)} MP`
                    });
                }
            } else if (isVideo && this.videoEl.videoWidth) {
                metadata.push({
                    label: 'Dimensions',
                    value: `${this.videoEl.videoWidth} × ${this.videoEl.videoHeight}px`
                });

                if (this.videoEl.duration && !isNaN(this.videoEl.duration)) {
                    metadata.push({
                        label: 'Duration',
                        value: this.formatDuration(this.videoEl.duration)
                    });
                }
            }

            // File size (if available)
            if (current.size) {
                metadata.push({
                    label: 'File Size',
                    value: this.formatFileSize(current.size)
                });
            }

            // Render metadata
            this.infoContent.innerHTML = metadata.map(item => `
                <div class="mv-info-row">
                    <span class="mv-info-label">${item.label}</span>
                    <span class="mv-info-value">${item.value}</span>
                </div>
            `).join('');
        },

        getFilename(url) {
            try {
                const path = new URL(url).pathname;
                return decodeURIComponent(path.split('/').pop());
            } catch {
                return url.split('/').pop();
            }
        },

        formatDuration(seconds) {
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = Math.floor(seconds % 60);

            if (h > 0) {
                return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }
            return `${m}:${s.toString().padStart(2, '0')}`;
        },

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
        },
    };

    // Create video element if it doesn't exist
    if (!mv.videoEl) {
        mv.videoEl = document.createElement("video");
        mv.videoEl.id = "mv-video";
        mv.videoEl.controls = true;
        mv.videoEl.style.maxWidth = "100%";
        mv.videoEl.style.maxHeight = "90vh";
        mv.videoEl.style.display = "none";
        mv.stageEl.appendChild(mv.videoEl);
    }

    // Create play/pause button if it doesn't exist
    if (!mv.playPauseBtn) {
        mv.playPauseBtn = document.createElement("button");
        mv.playPauseBtn.id = "mv-play-pause";
        mv.playPauseBtn.className = "mv-control";
        mv.playPauseBtn.style.display = "none";
        mv.playPauseBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path d="M8 5v14l11-7z"/>
            </svg>
        `;
        mv.playPauseBtn.title = "Play/Pause (Space)";
        mv.playPauseBtn.onclick = () => mv.togglePlayPause();

        // Add button next to other controls
        // Add button next to other controls
        if (mv.downloadBtn && mv.downloadBtn.parentNode) {
            mv.downloadBtn.parentNode.insertBefore(mv.playPauseBtn, mv.downloadBtn);
        }
    }

    // Update play/pause button icon based on video state
    if (mv.videoEl) {
        mv.videoEl.addEventListener('play', () => {
            if (mv.playPauseBtn) {
                mv.playPauseBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                    </svg>
                `;
            }
        });

        mv.videoEl.addEventListener('pause', () => {
            if (mv.playPauseBtn) {
                mv.playPauseBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                `;
            }
        });
    }

    // Standard Bindings
    document.getElementById("mv-close").onclick = () => mv.close();
    document.getElementById("mv-prev").onclick = () => mv.prev();
    document.getElementById("mv-next").onclick = () => mv.next();
    document.getElementById("mv-backdrop").onclick = () => mv.close();
    document.getElementById("mv-delete").onclick = () => {
        const current = mv.media[mv.index];
        if (current?.id) {
            window.dispatchEvent(
                new CustomEvent("media-viewer:delete", {
                    detail: { id: current.id },
                }),
            );
        }
        mv.close();
    };
    document.getElementById("mv-download").onclick = (e) => {
        const current = mv.media[mv.index];
        if (current?.download) {
            mv.downloadBtn.href = current.download;
        } else {
            e.preventDefault();
        }
    };
    document.getElementById("mv-info").onclick = () => mv.toggleInfo();
    document.getElementById("mv-info-close").onclick = () => mv.closeInfo();

    document.addEventListener("keydown", (e) => {
        if (mv.viewer.classList.contains("hidden")) return;
        if (e.key === "Escape") mv.close();
        if (e.key === "ArrowLeft") mv.prev();
        if (e.key === "ArrowRight") mv.next();
        if (e.key === " " || e.key === "Spacebar") {
            e.preventDefault();
            mv.togglePlayPause();
        }
        if (e.key === "i" || e.key === "I") {
            e.preventDefault();
            mv.toggleInfo();
        }
    });

    const stage = document.getElementById("mv-stage");
    stage.addEventListener("touchstart", (e) => mv.handleTouchStart(e), {
        passive: true,
    });
    stage.addEventListener("touchend", (e) => mv.handleTouchEnd(e), {
        passive: true,
    });

    // Manual Trigger (Keep this for edge cases)
    window.addEventListener("media-viewer:open", (e) =>
        mv.open(e.detail.media || e.detail.images, e.detail.index),
    );

    // ==========================================
    //  AUTOMATION: THE GLOBAL LISTENER
    // ==========================================
    document.body.addEventListener("click", (e) => {
        const target = e.target;

        // 1. Is it media inside a Gallery?
        const gallery = target.closest(".mv-gallery");
        if (gallery && (target.tagName === "IMG" || target.tagName === "VIDEO")) {
            const tiles = [...gallery.querySelectorAll("img, video")];
            const sources = tiles.map((el) => ({
                src: el.getAttribute("data-full") || el.src,
                download: el.getAttribute("data-download") || el.src,
                id: el.dataset.mediaId ? Number(el.dataset.mediaId) : null,
                type: el.tagName === "VIDEO" ? "video" : "image",
                mimeType: el.dataset.mimeType || (el.tagName === "VIDEO" ? "video/mp4" : "image/jpeg"),
                canDelete:
                    typeof el.dataset.canDelete !== "undefined"
                        ? el.dataset.canDelete === "true"
                        : !!el.dataset.mediaId,
            }));
            const index = tiles.indexOf(target);

            mv.open(sources, index);
            return;
        }

        // 2. Is it a Single standalone image or video?
        if ((target.matches(".mv-single") && target.tagName === "IMG") ||
            (target.matches(".mv-single") && target.tagName === "VIDEO")) {
            const src = target.getAttribute("data-full") || target.src;
            const canDelete =
                typeof target.dataset.canDelete !== "undefined"
                    ? target.dataset.canDelete === "true"
                    : !!target.dataset.mediaId;
            const download =
                target.getAttribute("data-download") || target.src;
            mv.open(
                [
                    {
                        src,
                        download,
                        id: target.dataset.mediaId
                            ? Number(target.dataset.mediaId)
                            : null,
                        type: target.tagName === "VIDEO" ? "video" : "image",
                        mimeType: target.dataset.mimeType || (target.tagName === "VIDEO" ? "video/mp4" : "image/jpeg"),
                        canDelete,
                    },
                ],
                0,
            );
        }
    });
}
