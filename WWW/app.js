(function () {
    function initViewModeSelect() {
        const viewSelect = document.querySelector('select[name="view"][form="view-form"]');
        const viewForm = document.getElementById('view-form');
        if (viewSelect && viewForm) {
            viewSelect.addEventListener('change', () => viewForm.submit());
        }
    }

    function normalizeSnippet(value, maxLen = 160) {
        const cleaned = String(value || '').replace(/\s+/g, ' ').trim();
        if (!cleaned) return '';
        if (cleaned.length > maxLen) {
            return `${cleaned.slice(0, maxLen)}…`;
        }
        return cleaned;
    }

    function formatApiError(payload, fallback) {
        if (!payload || typeof payload !== 'object') {
            return fallback;
        }
        const status = typeof payload.http_status === 'number'
            ? payload.http_status
            : Number(payload.http_status || 0);
        if (status === 403) {
            return 'Zugriff verweigert (403).';
        }
        if (status === 404) {
            return 'Job nicht gefunden (evtl. bereits gelöscht).';
        }
        if (payload.code === 'non_json') {
            const snippet = normalizeSnippet(payload.error, 200);
            return `Server-Antwort ist kein JSON: ${snippet || '—'}`;
        }
        if (payload.error) {
            return payload.error;
        }
        return fallback;
    }

    function fetchJson(endpoint, options = {}) {
        return fetch(endpoint, options).then(async (resp) => {
            const contentType = resp.headers.get('Content-Type') || '';
            const textBody = await resp.text();
            if (contentType.includes('application/json')) {
                if (!textBody) {
                    return { ok: resp.ok, http_status: resp.status, status_text: resp.statusText };
                }
                try {
                    const data = JSON.parse(textBody);
                    if (data && typeof data === 'object') {
                        if (typeof data.http_status === 'undefined') {
                            data.http_status = resp.status;
                        }
                        if (typeof data.status_text === 'undefined') {
                            data.status_text = resp.statusText;
                        }
                    }
                    return data;
                } catch (err) {
                    return {
                        ok: false,
                        error: textBody,
                        http_status: resp.status,
                        status_text: resp.statusText,
                        code: 'non_json',
                    };
                }
            }
            return {
                ok: false,
                error: textBody,
                http_status: resp.status,
                status_text: resp.statusText,
                code: 'non_json',
            };
        });
    }

    function initTabs() {
        document.querySelectorAll('.tab-bar').forEach((bar) => {
            const buttons = Array.from(bar.querySelectorAll('.tab-button'));
            if (!buttons.length) return;
            const group = bar.dataset.tabGroup || null;
            const persist = bar.dataset.tabPersist || '';
            const panels = group
                ? Array.from(document.querySelectorAll(`[data-tab-panel="${group}"]`))
                : Array.from(document.querySelectorAll('.tab-content'));

            const existingHash = window.location.hash ? window.location.hash.substring(1) : '';
            const hashMatchesPanel = existingHash && panels.some((panel) => panel.id === existingHash);

            function activate(tabId, updateHash = true) {
                buttons.forEach((btn) => btn.classList.toggle('active', btn.dataset.tab === tabId));
                panels.forEach((panel) => panel.classList.toggle('active', panel.id === tabId));
                if (persist === 'hash' && tabId && updateHash && !(!hashMatchesPanel && existingHash)) {
                    history.replaceState({}, '', `#${tabId}`);
                }
            }

            function resolveInitial() {
                const hash = window.location.hash ? window.location.hash.substring(1) : '';
                const params = new URLSearchParams(window.location.search);
                const queryTab = params.get('tab');
                const candidate = queryTab || hash;
                if (candidate && panels.some((panel) => panel.id === candidate)) {
                    return candidate;
                }
                const defaultButton = buttons.find((btn) => btn.dataset.tabDefault === 'true');
                return (defaultButton || buttons[0]).dataset.tab || '';
            }

            const initial = resolveInitial();
            if (initial) {
                activate(initial, hashMatchesPanel || !existingHash);
            }

            buttons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    const tabId = btn.dataset.tab || '';
                    if (tabId) {
                        activate(tabId, true);
                    }
                });
            });
        });
    }

    function initVersionSelectors() {
        const versionSelect = document.getElementById('version-select');
        if (versionSelect) {
            versionSelect.addEventListener('change', () => {
                const url = new URL(window.location.href);
                url.searchParams.set('version', versionSelect.value);
                url.searchParams.delete('asset');
                history.replaceState({}, '', url.toString());
                window.location.reload();
            });
        }

        const assetSelect = document.getElementById('asset-select');
        if (assetSelect) {
            assetSelect.addEventListener('change', () => {
                const url = new URL(window.location.href);
                url.searchParams.set('asset', assetSelect.value);
                history.replaceState({}, '', url.toString());
                window.location.reload();
            });
        }
    }

    function initTagActions() {
        const tagButtons = document.querySelectorAll('.tag-action');
        const actionField = document.getElementById('tag-action-field');
        tagButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (actionField) {
                    actionField.value = btn.dataset.action || 'tag_add';
                }
            });
        });
    }

    function initLightbox() {
        const lightbox = document.getElementById('fullscreen-viewer');
        const preview = document.querySelector('.full-preview');
        if (!lightbox || !preview) return;
        preview.addEventListener('click', () => {
            lightbox.classList.remove('is-hidden');
        });
        const close = lightbox.querySelector('.lightbox-close');
        if (close) {
            close.addEventListener('click', () => {
                lightbox.classList.add('is-hidden');
            });
        }
        lightbox.addEventListener('click', (event) => {
            if (event.target === lightbox) {
                lightbox.classList.add('is-hidden');
            }
        });
    }

    function initManualPromptIndicators() {
        const inputs = document.querySelectorAll('textarea[name="_sv_manual_prompt"]');
        inputs.forEach((el) => {
            const indicator = document.createElement('div');
            indicator.className = 'action-note highlight manual-indicator';
            indicator.textContent = 'Manual override aktiv';
            indicator.style.display = el.value.trim() ? 'block' : 'none';
            if (el.parentElement) {
                el.parentElement.appendChild(indicator);
            }
            const update = () => {
                indicator.style.display = el.value.trim() ? 'block' : 'none';
            };
            el.addEventListener('input', update);
        });
    }

    function initPromptApply() {
        document.querySelectorAll('[data-prompt-apply]').forEach((button) => {
            button.addEventListener('click', () => {
                const promptTargetId = button.dataset.promptTarget || '';
                const negativeTargetId = button.dataset.negativeTarget || '';
                const promptValue = button.dataset.promptValue || '';
                const negativeValue = button.dataset.negativeValue || '';
                const promptTarget = promptTargetId ? document.getElementById(promptTargetId) : null;
                const negativeTarget = negativeTargetId ? document.getElementById(negativeTargetId) : null;
                if (promptTarget) {
                    promptTarget.value = promptValue;
                    promptTarget.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (negativeTarget) {
                    negativeTarget.value = negativeValue;
                    negativeTarget.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (promptTarget) {
                    promptTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    }

    function initPreviewControls() {
        const preview = document.querySelector('.full-preview');
        if (!preview) return;
        const lightbox = document.getElementById('fullscreen-viewer');
        let zoom = 1;

        function setZoom(value) {
            zoom = Math.min(3, Math.max(0.5, value));
            preview.style.transform = `scale(${zoom})`;
            preview.classList.toggle('is-zoomed', zoom !== 1);
        }

        function setMode(mode) {
            preview.classList.toggle('preview-actual', mode === 'actual');
            preview.classList.toggle('preview-fit', mode === 'fit');
            preview.style.transform = '';
            zoom = 1;
        }

        document.querySelectorAll('[data-preview-action]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.previewAction || '';
                if (action === 'fit') {
                    setMode('fit');
                } else if (action === 'actual') {
                    setMode('actual');
                } else if (action === 'zoom-in') {
                    setZoom(zoom + 0.2);
                } else if (action === 'zoom-out') {
                    setZoom(zoom - 0.2);
                } else if (action === 'fullscreen' && lightbox) {
                    lightbox.classList.remove('is-hidden');
                }
            });
        });

        setMode('fit');
    }

    function initVideoTools() {
        document.querySelectorAll('[data-video-tool]').forEach((tool) => {
            const video = tool.querySelector('video');
            if (!video) return;
            const speedSelect = tool.querySelector('[data-video-speed]');
            const loopToggle = tool.querySelector('[data-video-loop]');
            const jumpInput = tool.querySelector('[data-video-jump]');
            const jumpButton = tool.querySelector('[data-video-jump-btn]');
            const snapshotButton = tool.querySelector('[data-video-snapshot]');
            const canvas = tool.querySelector('canvas');

            if (speedSelect) {
                speedSelect.addEventListener('change', () => {
                    const value = Number(speedSelect.value || '1');
                    if (!Number.isNaN(value)) {
                        video.playbackRate = value;
                    }
                });
            }

            if (loopToggle) {
                loopToggle.addEventListener('click', () => {
                    video.loop = !video.loop;
                    loopToggle.setAttribute('aria-pressed', video.loop ? 'true' : 'false');
                    loopToggle.textContent = video.loop ? 'Loop aktiv' : 'Loop';
                });
            }

            if (jumpButton && jumpInput) {
                jumpButton.addEventListener('click', () => {
                    const seconds = Number(jumpInput.value || '0');
                    if (!Number.isNaN(seconds)) {
                        video.currentTime = Math.max(0, seconds);
                    }
                });
            }

            if (snapshotButton && canvas) {
                snapshotButton.addEventListener('click', () => {
                    const width = video.videoWidth || 0;
                    const height = video.videoHeight || 0;
                    if (!width || !height) return;
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    if (!ctx) return;
                    ctx.drawImage(video, 0, 0, width, height);
                    canvas.toBlob((blob) => {
                        if (!blob) return;
                        const url = URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = 'frame-screenshot.png';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        URL.revokeObjectURL(url);
                    }, 'image/png');
                });
            }
        });
    }

    function initForgeJobs() {
        const container = document.querySelector('[data-forge-jobs]');
        if (!container) return;
        const endpoint = container.dataset.endpoint || '';
        const thumbUrl = container.dataset.thumb || '';
        const pollMeta = document.getElementById('forge-poll-meta');
        if (!endpoint) return;

        function statusClass(status) {
            if (!status) return 'status-queued';
            const normalized = status.toLowerCase();
            if (['queued', 'running', 'done', 'error'].includes(normalized)) {
                return `status-${normalized}`;
            }
            return 'status-queued';
        }

        function renderJobs(jobs) {
            if (!jobs || jobs.length === 0) {
                container.innerHTML = '<div class="job-hint">Keine Forge-Jobs vorhanden.</div>';
                return;
            }
            container.innerHTML = '';
            jobs.forEach((job) => {
                const item = document.createElement('div');
                item.className = 'timeline-item';
                const cacheBust = job.version_token || job.updated_at || job.id;
                const thumbSrc = job.replaced && thumbUrl
                    ? `${thumbUrl}${thumbUrl.includes('?') ? '&' : '?'}v=${encodeURIComponent(cacheBust)}`
                    : thumbUrl;
                const samplerLabel = `${job.used_sampler || '–'} / ${job.used_scheduler || '–'}`;
                const decidedMode = job.decided_mode || job.generation_mode || job.mode || 'txt2img';
                const decidedReason = job.decided_reason || '–';
                const promptSource = job.used_prompt_source || job.prompt_source || '–';
                const negativeSource = job.used_negative_source || job.negative_mode || '–';
                const usedSeed = job.used_seed || job.seed || '–';
                const startTs = job.started_at || job.created_at || '–';
                const finishTs = job.finished_at || '';
                const ageLabel = job.age_label ? ` · Age ${job.age_label}` : '';
                const stuckBadge = job.stuck ? '<span class="status-badge status-error">stuck</span>' : '';
                const denoise = (job.decided_denoise !== null && job.decided_denoise !== undefined)
                    ? job.decided_denoise
                    : (job.denoise !== undefined ? job.denoise : '–');
                const modelLine = `${job.model || '–'}${job.fallback_model ? ' (Fallback)' : ''}`;
                const formatLine = `${job.orig_w || '–'}×${job.orig_h || '–'} ${job.orig_ext || '–'} → ${job.out_w || '–'}×${job.out_h || '–'} ${job.out_ext || '–'}`;
                const attemptLine = job.attempt_index ? `Attempt ${job.attempt_index}/${job.attempt_chain ? job.attempt_chain.length : 3}` : 'Attempt –';
                const errorBlock = job.error ? `<div class="job-error">${job.error}</div>` : '';
                const detailsId = `job-details-${job.id}`;
                const jobLabel = job.job_label ? ` · ${job.job_label}` : '';

                item.innerHTML = `
                    <div class="timeline-header">
                        <div class="timeline-title">Job #${job.id}${jobLabel}</div>
                        <span class="status-badge ${statusClass(job.status)}">${job.status || 'queued'}</span>
                        ${stuckBadge}
                    </div>
                    <div class="timeline-meta">${job.created_at || '–'} • ${job.updated_at || '–'} • ${startTs}${finishTs ? ` → ${finishTs}` : ''}${ageLabel}</div>
                    <div class="timeline-body">
                        <div class="meta-line"><span>Mode</span><strong>${decidedMode} (${job.mode || 'preview'})</strong><em class="small">${decidedReason}</em></div>
                        <div class="meta-line"><span>Seed/Denoise</span><strong>${usedSeed} / ${denoise}</strong></div>
                        <div class="meta-line"><span>Model</span><strong>${modelLine}</strong></div>
                        <div class="meta-line"><span>Sampler/Scheduler</span><strong>${samplerLabel} · ${attemptLine}</strong></div>
                        <div class="meta-line"><span>Format</span><strong>${formatLine} [${job.format_preserved === null ? '–' : (job.format_preserved ? '1:1' : 'konvertiert')}]</strong></div>
                        <div class="meta-line"><span>Prompt</span><strong>${promptSource}</strong></div>
                        <div class="meta-line"><span>Negativ</span><strong>${negativeSource}</strong></div>
                        <div class="meta-line"><span>Output</span><strong>${job.output_path || '–'}</strong></div>
                        <div class="meta-line"><span>Version</span><strong>${job.version_token || cacheBust || '–'}</strong></div>
                        ${errorBlock}
                    </div>
                    <details class="timeline-details" id="${detailsId}">
                        <summary>Details</summary>
                        <div class="meta-line"><span>Hash</span><strong>${job.old_hash || '–'} → ${job.new_hash || '–'}</strong></div>
                        <div class="meta-line"><span>Request</span><strong>${job.request_snippet || '–'}</strong></div>
                        <div class="meta-line"><span>Response</span><strong>${job.response_snippet || '–'}</strong></div>
                    </details>
                `;

                if (job.replaced && thumbSrc) {
                    const preview = document.getElementById('media-preview-thumb');
                    if (preview) {
                        preview.src = thumbSrc;
                    }
                }
                container.appendChild(item);
            });
        }

        const activeStatuses = ['queued', 'pending', 'created', 'running'];
        let pollTimer = null;
        let lastStatusLabel = '';

        function updatePollMeta(timeLabel, active) {
            if (!pollMeta) return;
            const suffix = active ? 'laufend' : 'inaktiv';
            pollMeta.textContent = `Letzter Poll: ${timeLabel || new Date().toISOString()} · ${suffix}${lastStatusLabel ? ` · ${lastStatusLabel}` : ''}`;
        }

        function renderError(message) {
            container.innerHTML = `<div class="job-hint error">${message}</div>`;
            updatePollMeta(null, true);
        }

        function scheduleNext(active) {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            if (active) {
                pollTimer = setTimeout(loadJobs, 2000);
            }
            updatePollMeta(new Date().toISOString(), active);
        }

        function loadJobs() {
            fetchJson(endpoint, { headers: { Accept: 'application/json' } })
                .then((data) => {
                    if (!data || data.ok === false) {
                        throw new Error(formatApiError(data, 'Job-Status konnte nicht geladen werden.'));
                    }
                    const jobs = data.jobs || [];
                    lastStatusLabel = jobs.length ? `Status: ${jobs.map((j) => j.status || 'queued').join(', ')}` : 'Keine Jobs';
                    renderJobs(jobs);
                    const active = jobs.some((job) => activeStatuses.includes((job.status || '').toLowerCase()));
                    const refreshCandidate = jobs.find((job) => job.auto_refresh);
                    if (refreshCandidate && !active && (refreshCandidate.status || '').toLowerCase() === 'done') {
                        const key = 'sv-repair-refresh';
                        const lastRefresh = sessionStorage.getItem(key);
                        if (String(refreshCandidate.id) !== lastRefresh) {
                            sessionStorage.setItem(key, String(refreshCandidate.id));
                            window.location.reload();
                            return;
                        }
                    }
                    scheduleNext(active);
                })
                .catch((err) => {
                    renderError(`Job-Status konnte nicht geladen werden (${err.message}).`);
                    scheduleNext(true);
                });
        }

        const forgeForm = document.getElementById('forge-form');
        if (forgeForm) {
            forgeForm.addEventListener('submit', () => {
                if (pollTimer) {
                    clearTimeout(pollTimer);
                }
                pollTimer = setTimeout(loadJobs, 2000);
            });
        }

        document.addEventListener('sv-forge-refresh', () => {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            loadJobs();
        });

        loadJobs();
    }

    function initForgeRepair() {
        const openButton = document.getElementById('forge-repair-open');
        const modal = document.getElementById('forge-repair-modal');
        const form = document.getElementById('forge-repair-form');
        const status = document.getElementById('forge-repair-status');
        const startButton = document.getElementById('forge-repair-start');
        if (!openButton || !modal || !form) return;

        const endpoint = modal.dataset.endpoint || '';

        function resetStatus() {
            if (!status) return;
            status.textContent = '';
            status.classList.add('is-hidden');
            status.classList.remove('error');
        }

        function openModal() {
            resetStatus();
            modal.classList.remove('is-hidden');
        }

        function closeModal() {
            modal.classList.add('is-hidden');
        }

        openButton.addEventListener('click', () => {
            if (openButton.disabled) return;
            openModal();
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.classList.contains('is-hidden')) {
                closeModal();
            }
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!endpoint) return;
            if (startButton) {
                startButton.disabled = true;
            }
            const formData = new FormData(form);
            fetchJson(endpoint, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            })
                .then((payload) => {
                    if (status) {
                        status.classList.remove('is-hidden');
                        status.classList.toggle('error', !payload || !payload.ok);
                        const fallback = payload && payload.ok ? 'Repair gestartet.' : 'Repair fehlgeschlagen.';
                        status.textContent = payload && payload.message
                            ? payload.message
                            : formatApiError(payload, fallback);
                    }
                    if (payload && payload.ok) {
                        document.dispatchEvent(new Event('sv-forge-refresh'));
                    }
                })
                .catch((err) => {
                    if (status) {
                        status.classList.remove('is-hidden');
                        status.classList.add('error');
                        status.textContent = `Repair fehlgeschlagen (${err.message}).`;
                    }
                })
                .finally(() => {
                    if (startButton) {
                        startButton.disabled = false;
                    }
                });
        });
    }

    function initRescanJobs() {
        const summary = document.querySelector('[data-rescan-summary]');
        if (!summary) return;
        const jobLine = document.getElementById('rescan-job-line');
        const pollMeta = document.getElementById('rescan-poll-meta');
        const rescanButton = document.getElementById('rescan-button');
        const scanRunAt = document.getElementById('scan-run-at');
        const scanMeta = document.getElementById('scan-meta');
        const scanTags = document.getElementById('scan-tags');
        const scanError = document.getElementById('scan-error');
        const scanEmpty = document.getElementById('scan-empty');
        const scanErrorCodeLine = document.getElementById('scan-error-code-line');
        const scanErrorCode = document.getElementById('scan-error-code');
        const scanHttpStatusLine = document.getElementById('scan-http-status-line');
        const scanHttpStatus = document.getElementById('scan-http-status');
        const scanEndpointLine = document.getElementById('scan-endpoint-line');
        const scanEndpoint = document.getElementById('scan-endpoint');
        const scanResponseTypeLine = document.getElementById('scan-response-type-line');
        const scanResponseType = document.getElementById('scan-response-type');
        const scanBodySnippetLine = document.getElementById('scan-body-snippet-line');
        const scanBodySnippet = document.getElementById('scan-body-snippet');
        const rescanErrorBox = document.getElementById('rescan-last-error');
        const endpoint = summary.dataset.endpoint || '';
        const hasInternal = summary.dataset.internal === 'true';
        if (!endpoint) return;

        const activeStatuses = ['queued', 'running'];
        let pollTimer = null;

        function renderState(payload) {
            const jobs = payload.jobs || [];
            const latestScan = payload.latest_scan || null;
            const first = jobs[0] || null;
            if (latestScan) {
                if (scanRunAt) {
                    scanRunAt.textContent = latestScan.run_at || '—';
                }
                if (scanMeta) {
                    const hasNsfwVal = (latestScan.has_nsfw === null || typeof latestScan.has_nsfw === 'undefined')
                        ? null
                        : Number(latestScan.has_nsfw);
                    const flagText = hasNsfwVal === null ? '–' : (hasNsfwVal === 1 ? 'NSFW' : 'SFW');
                    const metaParts = [
                        `Scanner: ${latestScan.scanner || 'unknown'}`,
                        `NSFW: ${latestScan.nsfw_score ?? '–'}`,
                        `Rating: ${(latestScan.rating ?? '–')}`,
                        `Flag: ${flagText}`,
                    ];
                    scanMeta.textContent = metaParts.join(' · ');
                }
                if (scanTags) {
                    const tagsVal = (typeof latestScan.tags_written === 'undefined' || latestScan.tags_written === null)
                        ? null
                        : Number(latestScan.tags_written);
                    scanTags.textContent = tagsVal === null ? '–' : `${tagsVal} Tags`;
                }
                if (scanEmpty) {
                    scanEmpty.classList.toggle('is-hidden', !!latestScan.run_at);
                }
                if (scanError) {
                    const err = latestScan.error || '';
                    scanError.textContent = err;
                    scanError.classList.toggle('is-hidden', !err);
                }
                const errorCodeVal = latestScan.error_code || '';
                if (scanErrorCodeLine) {
                    scanErrorCodeLine.classList.toggle('is-hidden', !errorCodeVal);
                }
                if (scanErrorCode) {
                    scanErrorCode.textContent = errorCodeVal;
                }
                const httpStatusVal = (typeof latestScan.http_status === 'undefined' || latestScan.http_status === null)
                    ? ''
                    : String(latestScan.http_status);
                if (scanHttpStatusLine) {
                    scanHttpStatusLine.classList.toggle('is-hidden', !httpStatusVal);
                }
                if (scanHttpStatus) {
                    scanHttpStatus.textContent = httpStatusVal;
                }
                const endpointVal = latestScan.endpoint || '';
                if (scanEndpointLine) {
                    scanEndpointLine.classList.toggle('is-hidden', !endpointVal);
                }
                if (scanEndpoint) {
                    scanEndpoint.textContent = endpointVal;
                }
                const responseTypeVal = latestScan.response_type_detected || '';
                if (scanResponseTypeLine) {
                    scanResponseTypeLine.classList.toggle('is-hidden', !responseTypeVal);
                }
                if (scanResponseType) {
                    scanResponseType.textContent = responseTypeVal;
                }
                let snippetVal = latestScan.body_snippet || '';
                if (snippetVal.length > 300) {
                    snippetVal = snippetVal.slice(0, 300);
                }
                if (scanBodySnippetLine) {
                    scanBodySnippetLine.classList.toggle('is-hidden', !snippetVal);
                }
                if (scanBodySnippet) {
                    scanBodySnippet.textContent = snippetVal;
                }
            } else {
                if (scanRunAt) {
                    scanRunAt.textContent = '—';
                }
                if (scanMeta) {
                    scanMeta.textContent = 'Kein Eintrag';
                }
                if (scanTags) {
                    scanTags.textContent = '–';
                }
                if (scanEmpty) {
                    scanEmpty.classList.remove('is-hidden');
                }
                if (scanError) {
                    scanError.classList.add('is-hidden');
                }
                if (scanErrorCodeLine) {
                    scanErrorCodeLine.classList.add('is-hidden');
                }
                if (scanHttpStatusLine) {
                    scanHttpStatusLine.classList.add('is-hidden');
                }
                if (scanEndpointLine) {
                    scanEndpointLine.classList.add('is-hidden');
                }
                if (scanResponseTypeLine) {
                    scanResponseTypeLine.classList.add('is-hidden');
                }
                if (scanBodySnippetLine) {
                    scanBodySnippetLine.classList.add('is-hidden');
                }
            }
            if (first && jobLine) {
                const status = (first.status || 'queued').toLowerCase();
                const startTs = first.started_at || first.created_at || '';
                const finishTs = first.finished_at || first.completed_at || '';
                const ageLabel = first.age_label ? ` · Age ${first.age_label}` : '';
                const stuckBadge = first.stuck ? '<span class="status-badge status-error">stuck</span>' : '';
                jobLine.innerHTML = `<span>Rescan Job</span><strong>${status}</strong><em class="small">${startTs || ''}${finishTs ? ` → ${finishTs}` : ''}${ageLabel}</em>${stuckBadge}${first.error ? `<div class="job-error inline">${first.error}</div>` : ''}`;
            } else if (jobLine) {
                jobLine.innerHTML = '<span>Rescan Job</span><strong>none</strong><em class="small">Kein Eintrag</em>';
            }
            if (rescanErrorBox) {
                const jobError = jobs.find((job) => job.error);
                if (jobError && jobError.error) {
                    rescanErrorBox.textContent = `Letzter Fehler: ${jobError.error}`;
                    rescanErrorBox.classList.remove('is-hidden');
                } else {
                    rescanErrorBox.textContent = '';
                    rescanErrorBox.classList.add('is-hidden');
                }
            }
            if (rescanButton) {
                const activeJob = activeStatuses.includes((first?.status || '').toLowerCase());
                rescanButton.disabled = activeJob || !hasInternal;
                rescanButton.classList.toggle('muted', rescanButton.disabled);
            }
            const active = jobs.some((job) => activeStatuses.includes((job.status || '').toLowerCase()));
            if (pollMeta) {
                pollMeta.textContent = `Letzter Poll: ${payload.server_time || new Date().toISOString()} · ${active ? 'laufend' : 'inaktiv'}`;
            }
            return active;
        }

        function scheduleNext(active) {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            if (active) {
                pollTimer = setTimeout(loadState, 2000);
            }
        }

        function loadState() {
            fetchJson(endpoint, { headers: { Accept: 'application/json' } })
                .then((data) => {
                    if (!data || data.ok === false) {
                        throw new Error(formatApiError(data, 'Rescan-Status konnte nicht geladen werden.'));
                    }
                    const active = renderState(data);
                    scheduleNext(active);
                })
                .catch(() => {
                    scheduleNext(true);
                });
        }

        loadState();
    }

    function initScanJobsPanel() {
        const panel = document.querySelector('[data-scan-jobs]');
        if (!panel) return;

        const endpoint = panel.dataset.endpoint || '';
        const canManage = panel.dataset.manage === 'true';
        const list = panel.querySelector('[data-scan-jobs-list]');
        const pollMeta = panel.querySelector('[data-scan-jobs-poll]');
        const refreshBtn = panel.querySelector('[data-scan-jobs-refresh]');
        const pruneForm = panel.querySelector('[data-scan-jobs-prune]');
        if (!endpoint || !list) return;
        if (!canManage) {
            list.innerHTML = '<div class="muted">Internal-Key erforderlich.</div>';
            return;
        }

        const activeStatuses = ['queued', 'running'];
        let pollTimer = null;

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function badge(status) {
            const normalized = (status || '').toLowerCase();
            if (['done', 'ok'].includes(normalized)) return 'badge badge--ok';
            if (['error', 'failed'].includes(normalized)) return 'badge badge--error';
            if (['queued', 'running', 'pending'].includes(normalized)) return 'badge badge--info';
            if (['canceled'].includes(normalized)) return 'badge badge--warn';
            return 'badge';
        }

        function renderJobs(jobs) {
            if (!jobs.length) {
                list.innerHTML = '<div class="muted">Keine Scan-Jobs.</div>';
                return;
            }

            const cards = jobs.map((job) => {
                const status = job.status || 'queued';
                const type = job.type || 'scan';
                const created = job.created_at || '—';
                const started = job.started_at || '';
                const finished = job.finished_at || '';
                const media = job.media_id ? `Media #${job.media_id}` : null;
                let meta = '';
                if (type === 'scan_path') {
                    const path = job.path ? `Pfad: ${escapeHtml(job.path)}` : '';
                    const limit = job.limit ? `Limit: ${job.limit}` : '';
                    meta = [path, limit].filter(Boolean).join(' · ');
                } else if (type === 'rescan_media') {
                    meta = media ? escapeHtml(media) : '';
                } else if (type === 'scan_backfill_tags') {
                    const progress = job.progress || {};
                    const progressParts = [
                        typeof progress.processed !== 'undefined' ? `processed ${progress.processed}` : null,
                        typeof progress.enqueued !== 'undefined' ? `enqueued ${progress.enqueued}` : null,
                        typeof progress.deduped !== 'undefined' ? `deduped ${progress.deduped}` : null,
                    ].filter(Boolean);
                    meta = [`Mode: ${escapeHtml(job.mode || 'no_tags')}`, progressParts.join(' · ')].filter(Boolean).join(' · ');
                }

                const actionButtons = [];
                if (canManage && activeStatuses.includes(status.toLowerCase())) {
                    actionButtons.push(`<button type="button" class="btn btn--xs btn--ghost" data-job-action="cancel" data-job-id="${job.id}">Cancel</button>`);
                }
                if (canManage && ['done', 'error', 'canceled'].includes(status.toLowerCase())) {
                    actionButtons.push(`<button type="button" class="btn btn--xs btn--secondary" data-job-action="delete" data-job-id="${job.id}">Delete</button>`);
                }

                const errorLine = job.error ? `<div class="job-error">Fehler: ${escapeHtml(job.error)}</div>` : '';
                const timeLine = `${escapeHtml(created)}${started ? ` → ${escapeHtml(started)}` : ''}${finished ? ` → ${escapeHtml(finished)}` : ''}`;

                return `
                    <div class="job-card">
                        <div class="job-line">
                            <span class="badge badge--info">#${job.id}</span>
                            <span class="badge badge--info">${escapeHtml(type)}</span>
                            <span class="${badge(status)}">${escapeHtml(status)}</span>
                        </div>
                        <div class="job-meta">Zeit: ${timeLine}</div>
                        ${meta ? `<div class="job-meta">${meta}</div>` : ''}
                        ${errorLine}
                        ${actionButtons.length ? `<div class="job-actions">${actionButtons.join('')}</div>` : ''}
                    </div>
                `;
            });

            list.innerHTML = cards.join('');
        }

        function updatePollMeta(active) {
            if (!pollMeta) return;
            const label = active ? 'laufend' : 'inaktiv';
            pollMeta.textContent = `Letzter Poll: ${new Date().toISOString()} · ${label}`;
        }

        function scheduleNext(active) {
            if (pollTimer) {
                clearTimeout(pollTimer);
            }
            if (active) {
                pollTimer = setTimeout(loadJobs, 2000);
            }
            updatePollMeta(active);
        }

        function loadJobs() {
            fetchJson(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((data) => {
                    if (!data || data.ok === false) {
                        throw new Error(formatApiError(data, 'Scan-Jobs konnten nicht geladen werden.'));
                    }
                    const jobs = Array.isArray(data.jobs) ? data.jobs : [];
                    renderJobs(jobs);
                    const active = jobs.some((job) => activeStatuses.includes((job.status || '').toLowerCase()));
                    scheduleNext(active);
                })
                .catch((err) => {
                    list.innerHTML = `<div class="job-error">Scan-Jobs konnten nicht geladen werden (${escapeHtml(err.message)}).</div>`;
                    scheduleNext(true);
                });
        }

        function buildAjaxUrl(action, id) {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('ajax', action);
            if (id) {
                url.searchParams.set('id', id);
            } else {
                url.searchParams.delete('id');
            }
            return url.toString();
        }

        panel.addEventListener('click', (event) => {
            const button = event.target.closest('[data-job-action]');
            if (!button) return;
            if (!canManage) return;
            const action = button.dataset.jobAction || '';
            const jobId = button.dataset.jobId || '';
            if (!jobId) return;
            if (action === 'cancel' && !window.confirm('Job wirklich abbrechen?')) return;
            if (action === 'delete' && !window.confirm('Job wirklich löschen?')) return;

            const ajaxAction = action === 'cancel' ? 'job_cancel' : 'job_delete';
            fetchJson(buildAjaxUrl(ajaxAction, jobId), { method: 'POST', credentials: 'same-origin' })
                .then((data) => {
                    if (data && data.ok === false) {
                        list.innerHTML = `<div class="job-error">${escapeHtml(formatApiError(data, 'Aktion fehlgeschlagen.'))}</div>`;
                    }
                    loadJobs();
                })
                .catch(() => loadJobs());
        });

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => loadJobs());
        }

        if (pruneForm) {
            pruneForm.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!canManage) return;
                if (!window.confirm('Fertige Scan-Jobs wirklich prunen?')) return;
                const formData = new FormData(pruneForm);
                fetchJson(buildAjaxUrl('jobs_prune'), {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                })
                    .then((data) => {
                        if (data && data.ok === false) {
                            list.innerHTML = `<div class="job-error">${escapeHtml(formatApiError(data, 'Prune fehlgeschlagen.'))}</div>`;
                        }
                        loadJobs();
                    })
                    .catch(() => loadJobs());
            });
        }

        loadJobs();
    }

    function initJobsPruneControls() {
        const containers = document.querySelectorAll('[data-jobs-prune]');
        if (!containers.length) return;

        const postAction = (endpoint, payload) => {
            const body = new URLSearchParams();
            Object.entries(payload).forEach(([key, value]) => {
                if (value === undefined || value === null || value === '') return;
                body.append(key, String(value));
            });
            return fetchJson(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                credentials: 'same-origin',
            });
        };

        const buildMessage = (result, note) => {
            if (!result) return 'Keine Antwort vom Server.';
            const parts = [
                `matched ${result.matched_count || 0}`,
                `deleted ${result.deleted_count || 0}`,
                `updated ${result.updated_count || 0}`,
                `blocked ${result.blocked_running_count || 0}`,
            ];
            if (note) {
                parts.push(note);
            }
            return parts.join(' · ');
        };

        containers.forEach((container) => {
            const endpoint = container.dataset.endpoint || 'jobs_prune.php';
            const message = container.querySelector('[data-jobs-prune-message]');
            const messageTitle = message ? message.querySelector('.action-feedback-title') : null;
            const messageBody = message ? message.querySelector('div:nth-child(2)') : null;

            const setMessage = (type, text) => {
                if (!message) return;
                message.classList.remove('success', 'error');
                if (type) {
                    message.classList.add(type);
                }
                if (messageTitle) {
                    messageTitle.textContent = type === 'error' ? 'Fehler' : 'Status';
                }
                if (messageBody) {
                    messageBody.textContent = text;
                } else {
                    message.textContent = text;
                }
            };

            container.addEventListener('click', (event) => {
                const button = event.target.closest('[data-jobs-prune-button]');
                if (!button) return;
                if (button.disabled) return;

                const confirmText = button.dataset.confirm || '';
                if (confirmText && !window.confirm(confirmText)) {
                    return;
                }

                const payload = {
                    group: button.dataset.group,
                    status: button.dataset.status,
                    scope: button.dataset.scope || 'all',
                    media_id: button.dataset.mediaId || '',
                    force: button.dataset.force || '0',
                    dry_run: button.dataset.dryRun || '0',
                };

                button.disabled = true;
                setMessage('success', 'Prune läuft...');
                postAction(endpoint, payload)
                    .then((data) => {
                        if (!data || !data.ok) {
                            throw new Error(formatApiError(data, 'Prune fehlgeschlagen.'));
                        }
                        setMessage('success', buildMessage(data.result, data.note));
                    })
                    .catch((err) => {
                        setMessage('error', err.message);
                    })
                    .finally(() => {
                        button.disabled = false;
                    });
            });
        });
    }

    function initOllamaDashboard() {
        const root = document.querySelector('[data-ollama-dashboard]');
        if (!root) return;

        const endpoint = root.dataset.endpoint || 'ollama.php';
        const pollInterval = Number(root.dataset.pollInterval || '10000');
        const heartbeatStaleSec = Number(root.dataset.heartbeatStale || '180');
        const statusOrder = {
            running: 1,
            queued: 2,
            pending: 3,
            done: 4,
            error: 5,
            cancelled: 6,
        };
        const progressState = new Map();
        let activeSortKey = null;
        let activeSortDir = 'asc';

        const statusClass = (status) => {
            if (!status) return 'status-queued';
            const normalized = status.toLowerCase();
            if (normalized === 'running') return 'status-running';
            if (normalized === 'queued' || normalized === 'pending') return 'status-queued';
            if (normalized === 'done') return 'status-done';
            if (normalized === 'error') return 'status-error';
            if (normalized === 'cancelled') return 'status-cancelled';
            return 'status-queued';
        };

        const messageBox = root.querySelector('[data-ollama-message]');
        const messageTitle = messageBox ? messageBox.querySelector('.action-feedback-title') : null;
        const messageText = messageBox ? messageBox.querySelector('div:nth-child(2)') : null;
        const systemStatusEl = root.querySelector('[data-ollama-system-status]');
        const quickEnqueueBtn = root.querySelector('[data-ollama-quick-enqueue]');
        const runBtn = root.querySelector('[data-ollama-run]');
        const autoRunBtn = root.querySelector('[data-ollama-auto-run]');
        const runBatchInput = root.querySelector('[data-ollama-run-batch]');
        const runSecondsInput = root.querySelector('[data-ollama-run-seconds]');
        let autoRunEnabled = false;
        let autoRunBusy = false;
        let autoRunNextAllowedAt = 0;
        let runRequestInFlight = false;
        let lastCounts = { queued: 0, pending: 0 };

        function showMessage(type, title, text) {
            if (!messageBox) return;
            messageBox.classList.remove('success', 'error');
            if (type) {
                messageBox.classList.add(type);
            }
            if (messageTitle) {
                messageTitle.textContent = title;
            }
            if (messageText) {
                messageText.textContent = text;
            }
        }

        function setRunControlsDisabled(disabled) {
            if (runBtn) runBtn.disabled = disabled;
            if (autoRunBtn) autoRunBtn.disabled = disabled;
        }

        function postAction(payload) {
            const body = new URLSearchParams();
            Object.entries(payload).forEach(([key, value]) => {
                if (value === undefined || value === null) return;
                body.append(key, String(value));
            });
            return fetchJson(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                credentials: 'same-origin',
            });
        }

        function updateStatusCounts(data) {
            const counts = data.counts || {};
            Object.entries(counts).forEach(([status, value]) => {
                const el = root.querySelector(`[data-status-count="${status}"]`);
                if (el) el.textContent = String(value);
            });
            lastCounts = {
                queued: Number(counts.queued || 0),
                pending: Number(counts.pending || 0),
            };
            const modeCounts = data.mode_counts || {};
            Object.entries(modeCounts).forEach(([mode, countsForMode]) => {
                if (!countsForMode) return;
                Object.entries(countsForMode).forEach(([status, value]) => {
                    const el = root.querySelector(`[data-mode-count="${mode}:${status}"]`);
                    if (el) el.textContent = String(value);
                });
            });
            const logsPath = data.logs_path;
            if (logsPath) {
                const logsEl = root.querySelector('[data-logs-path]');
                if (logsEl) logsEl.textContent = logsPath;
            }
            if ('running' in data) {
                const runningEl = root.querySelector('[data-ollama-running]');
                if (runningEl) runningEl.textContent = String(data.running ?? '0');
            }
            if ('max_concurrency' in data) {
                const maxEl = root.querySelector('[data-ollama-max-concurrency]');
                if (maxEl) maxEl.textContent = String(data.max_concurrency ?? '0');
            }
            if ('runner_locked' in data) {
                const lockedEl = root.querySelector('[data-ollama-runner-locked]');
                if (lockedEl) {
                    lockedEl.textContent = data.runner_locked ? 'ja' : 'nein';
                }
            }
        }

        function updateGlobalStatus(data) {
            if (!systemStatusEl) return;
            const globalStatus = data.global_status || {};
            const entries = Object.entries(globalStatus).filter(([, value]) => value && value.active);
            systemStatusEl.innerHTML = '';

            if (entries.length === 0) {
                const hint = document.createElement('div');
                hint.className = 'hint small';
                hint.textContent = 'Keine aktiven System-Blocker.';
                systemStatusEl.appendChild(hint);
                return;
            }

            const list = document.createElement('div');
            list.className = 'status-stack';
            entries.forEach(([key, value]) => {
                const row = document.createElement('div');
                row.className = 'action-note error';
                const label = document.createElement('strong');
                const labelMap = {
                    ollama_down: 'Ollama down',
                    missing_prompts: 'Prompt-Templates fehlen',
                    missing_models: 'Modelle fehlen',
                };
                label.textContent = labelMap[key] || key;
                const text = document.createElement('div');
                const message = value.message ? ` ${value.message}` : '';
                const since = value.since ? ` (seit ${value.since})` : '';
                text.textContent = `${message}${since}`.trim() || 'aktiv';
                row.appendChild(label);
                row.appendChild(text);

                if (value.details && typeof value.details === 'object') {
                    const details = document.createElement('div');
                    details.className = 'hint small';
                    details.textContent = JSON.stringify(value.details);
                    row.appendChild(details);
                }

                list.appendChild(row);
            });
            systemStatusEl.appendChild(list);
        }

        function updateWarnings(row, status, progressKey, heartbeatAt) {
            const jobId = row.dataset.jobId;
            const warnings = [];
            const now = Date.now();
            const heartbeatMs = heartbeatAt ? Date.parse(heartbeatAt) : null;

            if (status === 'running') {
                if (heartbeatMs && Number.isFinite(heartbeatMs)) {
                    if (now - heartbeatMs > heartbeatStaleSec * 1000) {
                        warnings.push('Heartbeat alt');
                    }
                } else {
                    warnings.push('Heartbeat fehlt');
                }
            }

            const prev = progressState.get(jobId) || { key: null, unchanged: 0, status: null };
            if (status === 'running') {
                if (prev.key === progressKey && prev.status === 'running') {
                    prev.unchanged += 1;
                } else {
                    prev.unchanged = 0;
                }
                if (prev.unchanged >= 2) {
                    warnings.push('Progress hängt');
                }
            } else {
                prev.unchanged = 0;
            }
            prev.key = progressKey;
            prev.status = status;
            progressState.set(jobId, prev);

            const warningsCell = row.querySelector('[data-field="warnings"]');
            if (warningsCell) {
                if (warnings.length === 0) {
                    warningsCell.textContent = '–';
                } else {
                    warningsCell.innerHTML = warnings
                        .map((item) => `<span class="pill pill-warn">${item}</span>`)
                        .join(' ');
                }
            }
        }

        function updateJobRow(row, data) {
            if (!data) return;
            const status = String(data.status || '');
            const progressBits = Number(data.progress_bits || 0);
            const progressTotal = Number(data.progress_bits_total || 0);
            const heartbeatAt = data.heartbeat_at || '';
            const lastError = data.last_error_code || '';
            const statusBadge = row.querySelector('[data-field="status"]');
            if (statusBadge) {
                statusBadge.textContent = status || '–';
                statusBadge.className = `status-badge ${statusClass(status)}`;
            }
            const progressCell = row.querySelector('[data-field="progress"]');
            if (progressCell) {
                const percent = progressTotal > 0 ? Math.round((progressBits / progressTotal) * 100) : 0;
                progressCell.textContent = `${progressBits}/${progressTotal}${progressTotal > 0 ? ` (${percent}%)` : ''}`;
            }
            const heartbeatCell = row.querySelector('[data-field="heartbeat"]');
            if (heartbeatCell) {
                heartbeatCell.textContent = heartbeatAt ? heartbeatAt : '–';
            }
            const errorCell = row.querySelector('[data-field="error"]');
            if (errorCell) {
                errorCell.textContent = lastError ? lastError : '–';
            }
            if ('model' in data) {
                const modelCell = row.querySelector('[data-field="model"]');
                if (modelCell) {
                    modelCell.textContent = data.model ? data.model : '–';
                }
            }
            if ('stage_version' in data) {
                const stageCell = row.querySelector('[data-field="stage"]');
                if (stageCell) {
                    stageCell.textContent = data.stage_version ? data.stage_version : '–';
                }
            }

            const progressRatio = progressTotal > 0 ? (progressBits / progressTotal) : 0;
            row.dataset.progress = String(progressRatio);
            row.dataset.heartbeat = heartbeatAt ? String(Math.floor(Date.parse(heartbeatAt) / 1000)) : '0';
            row.dataset.status = status;
            row.dataset.statusOrder = String(statusOrder[status] || 99);

            updateWarnings(row, status, `${progressBits}/${progressTotal}`, heartbeatAt);
        }

        function applySort() {
            const table = root.querySelector('[data-ollama-jobs]');
            if (!table || !activeSortKey) return;
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr[data-job-id]'));
            const dir = activeSortDir === 'desc' ? -1 : 1;

            rows.sort((a, b) => {
                let aVal = 0;
                let bVal = 0;
                if (activeSortKey === 'status') {
                    aVal = Number(a.dataset.statusOrder || 99);
                    bVal = Number(b.dataset.statusOrder || 99);
                } else if (activeSortKey === 'heartbeat') {
                    aVal = Number(a.dataset.heartbeat || 0);
                    bVal = Number(b.dataset.heartbeat || 0);
                } else if (activeSortKey === 'progress') {
                    aVal = Number(a.dataset.progress || 0);
                    bVal = Number(b.dataset.progress || 0);
                }
                if (aVal === bVal) return 0;
                return aVal > bVal ? dir : -dir;
            });

            rows.forEach((row) => tbody.appendChild(row));
        }

        function pollStatus() {
            postAction({ action: 'status' })
                .then((data) => {
                    if (data && data.ok) {
                        updateStatusCounts(data);
                        updateGlobalStatus(data);
                        if (autoRunEnabled) {
                            triggerAutoRun();
                        }
                    }
                })
                .catch(() => {});
        }

        function pollJobs() {
            const rows = Array.from(root.querySelectorAll('tr[data-job-id]'));
            if (rows.length === 0) return;
            Promise.all(rows.map((row) => {
                const jobId = row.dataset.jobId;
                if (!jobId) return null;
                return postAction({ action: 'job_status', job_id: jobId })
                    .then((data) => {
                        if (data && data.ok && data.job) {
                            updateJobRow(row, data.job);
                        }
                    })
                    .catch(() => {});
            })).finally(() => applySort());
        }

        function handleActionClick(event) {
            const button = event.target.closest('button[data-action]');
            if (!button) return;
            const action = button.dataset.action;
            if (action === 'cancel') {
                const jobId = button.dataset.jobId;
                if (!jobId) return;
                showMessage('success', 'Abbrechen', `Job #${jobId} wird abgebrochen...`);
                postAction({ action: 'cancel', job_id: jobId })
                    .then((data) => {
                        if (!data || !data.ok) {
                            const errorMessage = formatApiError(data, 'Abbrechen fehlgeschlagen.');
                            if (data && Number(data.http_status || 0) === 404) {
                                const row = button.closest('tr');
                                if (row) {
                                    row.remove();
                                }
                                pollStatus();
                            }
                            throw new Error(errorMessage);
                        }
                        showMessage('success', 'Abbrechen', `Job #${jobId} abgebrochen.`);
                        pollJobs();
                    })
                    .catch((err) => showMessage('error', 'Abbrechen', err.message));
            }
            if (action === 'delete') {
                const mediaId = button.dataset.mediaId;
                const mode = button.dataset.mode;
                if (!mediaId || !mode) return;
                if (!window.confirm(`Ergebnisse für Media ${mediaId} (${mode}) löschen?`)) return;
                showMessage('success', 'Löschen', `Löschen für Media ${mediaId} (${mode})...`);
                postAction({ action: 'delete', media_id: mediaId, mode })
                    .then((data) => {
                        if (!data || !data.ok) {
                            throw new Error(formatApiError(data, 'Löschen fehlgeschlagen.'));
                        }
                        showMessage('success', 'Löschen', `Media ${mediaId} (${mode}) gelöscht.`);
                        pollStatus();
                    })
                    .catch((err) => showMessage('error', 'Löschen', err.message));
            }
            if (action === 'requeue') {
                const mediaId = button.dataset.mediaId;
                const mode = button.dataset.mode;
                if (!mediaId || !mode) return;
                showMessage('success', 'Neu einreihen', `Neu einreihen für Media ${mediaId} (${mode})...`);
                const filters = { media_id: Number(mediaId), force: 1, all: 1, limit: 1 };
                postAction({ action: 'enqueue', mode, filters: JSON.stringify(filters) })
                    .then((data) => {
                        if (!data || !data.ok) {
                            throw new Error(formatApiError(data, 'Neu einreihen fehlgeschlagen.'));
                        }
                        showMessage('success', 'Neu einreihen', `Neu einreihen abgeschlossen (Media ${mediaId}).`);
                        pollStatus();
                    })
                    .catch((err) => showMessage('error', 'Neu einreihen', err.message));
            }
        }

        function getRunConfig() {
            const batch = runBatchInput ? Number(runBatchInput.value || 5) : 5;
            const maxSeconds = runSecondsInput ? Number(runSecondsInput.value || 20) : 20;
            return {
                batch: Number.isFinite(batch) && batch > 0 ? batch : 5,
                maxSeconds: Number.isFinite(maxSeconds) && maxSeconds > 0 ? maxSeconds : 20,
            };
        }

        function triggerRun() {
            if (runRequestInFlight) {
                return Promise.resolve({ status: 'inflight' });
            }
            const config = getRunConfig();
            runRequestInFlight = true;
            setRunControlsDisabled(true);
            showMessage('success', 'Batch', `Batch läuft (${config.batch}, ${config.maxSeconds}s).`);
            return postAction({ action: 'run', batch: config.batch, max_seconds: config.maxSeconds })
                .then((data) => {
                    if (data && data.ok === false && (data.status === 'locked' || data.status === 'busy' || data.status === 'blocked' || data.status === 'start_failed')) {
                        let text = '';
                        if (data.status === 'locked') {
                            text = 'Launcher gesperrt (ein anderer Start läuft).';
                        } else if (data.status === 'busy') {
                            text = `Ollama beschäftigt (laufend ${data.running ?? 0} / max ${data.max_concurrency ?? 0}).`;
                        } else if (data.status === 'blocked') {
                            text = `Start blockiert (${data.reason || 'preflight'}).`;
                        } else if (data.status === 'start_failed') {
                            text = 'Worker-Start nicht verifiziert (kein Lock/Heartbeat).';
                        }
                        showMessage('success', 'Status', text);
                        return { status: data.status };
                    }
                    if (!data || !data.ok) {
                        throw new Error(formatApiError(data, 'Batch fehlgeschlagen.'));
                    }
                    const pidLabel = data.pid ? ` (PID ${data.pid})` : '';
                    showMessage('success', 'Batch', `Worker gestartet${pidLabel}.`);
                    pollStatus();
                    pollJobs();
                    return { status: 'ok' };
                })
                .catch((err) => {
                    showMessage('error', 'Batch', err.message);
                    return { status: 'error' };
                })
                .finally(() => {
                    runRequestInFlight = false;
                    setRunControlsDisabled(false);
                });
        }

        function triggerAutoRun() {
            if (!autoRunEnabled || autoRunBusy) return;
            const queued = Number(lastCounts.queued || 0) + Number(lastCounts.pending || 0);
            if (queued <= 0) return;
            if (Date.now() < autoRunNextAllowedAt) return;
            autoRunBusy = true;
            triggerRun().then((result) => {
                if (result && (result.status === 'busy' || result.status === 'locked')) {
                    autoRunNextAllowedAt = Date.now() + pollInterval;
                }
            }).finally(() => {
                autoRunBusy = false;
            });
        }

        const enqueueForm = root.querySelector('[data-ollama-enqueue]');
        if (enqueueForm) {
            enqueueForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const formData = new FormData(enqueueForm);
                const mode = String(formData.get('mode') || 'all');
                const filters = {
                    limit: Number(formData.get('limit') || 50),
                    since: String(formData.get('since') || ''),
                    missing_title: formData.get('missing_title') ? 1 : 0,
                    missing_caption: formData.get('missing_caption') ? 1 : 0,
                    all: formData.get('all') ? 1 : 0,
                };
                showMessage('success', 'Einreihen', `Einreihen läuft (${mode}).`);
                postAction({ action: 'enqueue', mode, filters: JSON.stringify(filters) })
                    .then((data) => {
                        if (!data || !data.ok) {
                            throw new Error(formatApiError(data, 'Einreihen fehlgeschlagen.'));
                        }
                        showMessage('success', 'Einreihen', `Einreihen OK: ${JSON.stringify(data.summary || {})}`);
                        pollStatus();
                        pollJobs();
                    })
                    .catch((err) => showMessage('error', 'Einreihen', err.message));
            });
        }

        root.addEventListener('click', handleActionClick);

        if (quickEnqueueBtn) {
            quickEnqueueBtn.addEventListener('click', () => {
                showMessage('success', 'Einreihen', 'Alles einreihen läuft.');
                postAction({ action: 'enqueue', mode: 'all' })
                    .then((data) => {
                        if (!data || !data.ok) {
                            throw new Error(formatApiError(data, 'Einreihen fehlgeschlagen.'));
                        }
                        showMessage('success', 'Einreihen', `Einreihen OK: ${JSON.stringify(data.summary || {})}`);
                        pollStatus();
                        pollJobs();
                    })
                    .catch((err) => showMessage('error', 'Einreihen', err.message));
            });
        }

        if (runBtn) {
            runBtn.addEventListener('click', () => {
                triggerRun();
            });
        }

        if (autoRunBtn) {
            autoRunBtn.addEventListener('click', () => {
                autoRunEnabled = !autoRunEnabled;
                autoRunBtn.setAttribute('aria-pressed', autoRunEnabled ? 'true' : 'false');
                autoRunBtn.textContent = autoRunEnabled ? 'Auto-Lauf: An' : 'Auto-Lauf: Aus';
                if (autoRunEnabled) {
                    triggerAutoRun();
                }
            });
        }

        const table = root.querySelector('[data-ollama-jobs]');
        if (table) {
            table.querySelectorAll('th.sortable').forEach((th) => {
                th.addEventListener('click', () => {
                    const key = th.dataset.sortKey;
                    if (!key) return;
                    if (activeSortKey === key) {
                        activeSortDir = activeSortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        activeSortKey = key;
                        activeSortDir = 'asc';
                    }
                    applySort();
                });
            });
        }

        pollStatus();
        pollJobs();
        setInterval(() => {
            pollStatus();
            pollJobs();
        }, pollInterval);
    }

    function initOllamaMediaAnalyze() {
        const button = document.querySelector('[data-ollama-analyze]');
        if (!button) return;

        const endpoint = button.dataset.endpoint || 'ollama.php';
        const mediaId = button.dataset.mediaId || '';
        const runBatch = Number(button.dataset.runBatch || 2);
        const runSeconds = Number(button.dataset.runSeconds || 10);
        const message = document.querySelector('[data-ollama-analyze-message]');
        const messageTitle = message ? message.querySelector('.action-feedback-title') : null;
        const messageBody = message ? message.querySelector('div:nth-child(2)') : null;

        const setMessage = (type, text) => {
            if (!message) return;
            message.classList.remove('success', 'error');
            if (type) {
                message.classList.add(type);
            }
            if (messageTitle) {
                messageTitle.textContent = type === 'error' ? 'Fehler' : 'Status';
            }
            if (messageBody) {
                messageBody.textContent = text;
            } else {
                message.textContent = text;
            }
        };

        const postAction = (payload) => {
            const body = new URLSearchParams();
            Object.entries(payload).forEach(([key, value]) => {
                if (value === undefined || value === null) return;
                body.append(key, String(value));
            });
            return fetchJson(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                credentials: 'same-origin',
            });
        };

        button.addEventListener('click', () => {
            if (!mediaId) return;
            button.disabled = true;
            setMessage('success', 'Einreihen läuft...');
            postAction({ action: 'enqueue', mode: 'all', media_id: mediaId })
                .then((data) => {
                    if (!data || !data.ok) {
                        throw new Error(formatApiError(data, 'Einreihen fehlgeschlagen.'));
                    }
                    setMessage('success', 'Einreihen OK, starte Batch...');
                    return postAction({
                        action: 'run',
                        batch: Number.isFinite(runBatch) && runBatch > 0 ? runBatch : 2,
                        max_seconds: Number.isFinite(runSeconds) && runSeconds > 0 ? runSeconds : 10,
                    });
                })
                .then((data) => {
                    if (data && data.ok === false && (data.status === 'locked' || data.status === 'busy')) {
                        const text = data.status === 'locked'
                            ? 'Worker gesperrt (ein anderer Lauf aktiv).'
                            : `Ollama beschäftigt (laufend ${data.running ?? 0} / max ${data.max_concurrency ?? 0}).`;
                        setMessage('success', text);
                        return;
                    }
                    if (!data || !data.ok) {
                        throw new Error(formatApiError(data, 'Batch fehlgeschlagen.'));
                    }
                    setMessage('success', `Batch OK (verarbeitet: ${data.processed || 0}).`);
                })
                .catch((err) => {
                    setMessage('error', err.message);
                })
                .finally(() => {
                    button.disabled = false;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initViewModeSelect();
        initTabs();
        initVersionSelectors();
        initTagActions();
        initLightbox();
        initManualPromptIndicators();
        initPromptApply();
        initPreviewControls();
        initVideoTools();
        initForgeJobs();
        initForgeRepair();
        initRescanJobs();
        initScanJobsPanel();
        initJobsPruneControls();
        initOllamaDashboard();
        initOllamaMediaAnalyze();
    });
})();
