(function () {
    function initViewModeSelect() {
        const viewSelect = document.querySelector('select[name="view"][form="view-form"]');
        const viewForm = document.getElementById('view-form');
        if (viewSelect && viewForm) {
            viewSelect.addEventListener('change', () => viewForm.submit());
        }
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

                item.innerHTML = `
                    <div class="timeline-header">
                        <div class="timeline-title">Job #${job.id}</div>
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
            fetch(endpoint, { headers: { Accept: 'application/json' } })
                .then((resp) => {
                    if (!resp.ok) {
                        throw new Error(`HTTP ${resp.status}`);
                    }
                    return resp.json();
                })
                .then((data) => {
                    const jobs = data.jobs || [];
                    lastStatusLabel = jobs.length ? `Status: ${jobs.map((j) => j.status || 'queued').join(', ')}` : 'Keine Jobs';
                    renderJobs(jobs);
                    const active = jobs.some((job) => activeStatuses.includes((job.status || '').toLowerCase()));
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

        loadJobs();
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
            fetch(endpoint, { headers: { Accept: 'application/json' } })
                .then((resp) => resp.json())
                .then((data) => {
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
            fetch(endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((resp) => {
                    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                    return resp.json();
                })
                .then((data) => {
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
            fetch(buildAjaxUrl(ajaxAction, jobId), { method: 'POST', credentials: 'same-origin' })
                .then((resp) => resp.json())
                .then(() => loadJobs())
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
                fetch(buildAjaxUrl('jobs_prune'), {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                })
                    .then((resp) => resp.json())
                    .then(() => loadJobs())
                    .catch(() => loadJobs());
            });
        }

        loadJobs();
    }

    document.addEventListener('DOMContentLoaded', () => {
        initViewModeSelect();
        initTabs();
        initVersionSelectors();
        initTagActions();
        initLightbox();
        initManualPromptIndicators();
        initVideoTools();
        initForgeJobs();
        initRescanJobs();
        initScanJobsPanel();
    });
})();
