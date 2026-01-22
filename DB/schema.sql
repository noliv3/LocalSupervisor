PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS media (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    path            TEXT NOT NULL,
    type            TEXT NOT NULL,             -- image, video
    source          TEXT NOT NULL,             -- comfy, sd, pinokio, forge, other
    width           INTEGER,
    height          INTEGER,
    duration        REAL,                      -- Sekunden (Videos)
    fps             REAL,                      -- Videos
    filesize        INTEGER,
    hash            TEXT,                      -- MD5/SHA1
    created_at      TEXT,                      -- ISO-8601
    imported_at     TEXT NOT NULL,             -- ISO-8601
    rating          INTEGER NOT NULL DEFAULT 0, -- 0=unrated,1=safe,2=questionable,3=explicit
    has_nsfw        INTEGER NOT NULL DEFAULT 0,
    parent_media_id INTEGER,
    status          TEXT NOT NULL DEFAULT 'active', -- active, archived, deleted_logical
    lifecycle_status TEXT NOT NULL DEFAULT 'active', -- active, review, pending_delete, deleted_logical
    lifecycle_reason TEXT,
    quality_status   TEXT NOT NULL DEFAULT 'unknown', -- unknown, ok, review, blocked
    quality_score    REAL,
    quality_notes    TEXT,
    deleted_at       TEXT,

    FOREIGN KEY (parent_media_id) REFERENCES media(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_media_hash
    ON media(hash);

CREATE INDEX IF NOT EXISTS idx_media_source
    ON media(source);

CREATE INDEX IF NOT EXISTS idx_media_type
    ON media(type);

CREATE INDEX IF NOT EXISTS idx_media_rating
    ON media(rating);

CREATE INDEX IF NOT EXISTS idx_media_status
    ON media(status);

CREATE INDEX IF NOT EXISTS idx_media_lifecycle_status
    ON media(lifecycle_status);

CREATE INDEX IF NOT EXISTS idx_media_quality_status
    ON media(quality_status);

CREATE INDEX IF NOT EXISTS idx_media_imported_at
    ON media(imported_at);



CREATE TABLE IF NOT EXISTS tags (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT NOT NULL,
    type    TEXT NOT NULL DEFAULT 'content', -- content, style, character, nsfw, technical, other
    locked  INTEGER NOT NULL DEFAULT 0       -- 0/1
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_tags_name_type
    ON tags(name, type);

CREATE TABLE IF NOT EXISTS media_tags (
    media_id    INTEGER NOT NULL,
    tag_id      INTEGER NOT NULL,
    confidence  REAL NOT NULL DEFAULT 1.0,
    locked      INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (media_id, tag_id),
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)   REFERENCES tags(id)  ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_media_tags_media
    ON media_tags(media_id);

CREATE INDEX IF NOT EXISTS idx_media_tags_media_locked
    ON media_tags(media_id, locked);

CREATE INDEX IF NOT EXISTS idx_media_tags_tag
    ON media_tags(tag_id);



CREATE TABLE IF NOT EXISTS scan_results (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id    INTEGER NOT NULL,
    scanner     TEXT NOT NULL,         -- z. B. pixai_sensible
    run_at      TEXT NOT NULL,         -- ISO-8601
    nsfw_score  REAL,
    flags       TEXT,                  -- JSON: gore, nudity, etc.
    raw_json    TEXT,                  -- komplette Scannerantwort

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_scan_results_media
    ON scan_results(media_id);

CREATE INDEX IF NOT EXISTS idx_scan_results_scanner
    ON scan_results(scanner);



CREATE TABLE IF NOT EXISTS prompts (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id         INTEGER NOT NULL,
    prompt           TEXT,
    negative_prompt  TEXT,
    model            TEXT,
    sampler          TEXT,
    cfg_scale        REAL,
    steps            INTEGER,
    seed             TEXT,
    width            INTEGER,
    height           INTEGER,
    scheduler        TEXT,
    sampler_settings TEXT,  -- JSON
    loras            TEXT,  -- JSON
    controlnet       TEXT,  -- JSON
    source_metadata  TEXT,  -- Roh-Parameter/Metadaten

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_prompts_media
    ON prompts(media_id);


CREATE TABLE IF NOT EXISTS prompt_history (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id         INTEGER NOT NULL,
    prompt_id        INTEGER,
    version          INTEGER NOT NULL,
    source           TEXT NOT NULL,
    created_at       TEXT NOT NULL,
    prompt           TEXT,
    negative_prompt  TEXT,
    model            TEXT,
    sampler          TEXT,
    cfg_scale        REAL,
    steps            INTEGER,
    seed             TEXT,
    width            INTEGER,
    height           INTEGER,
    scheduler        TEXT,
    sampler_settings TEXT,
    loras            TEXT,
    controlnet       TEXT,
    source_metadata  TEXT,
    raw_text         TEXT,

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (prompt_id) REFERENCES prompts(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_prompt_history_media
    ON prompt_history(media_id);

CREATE INDEX IF NOT EXISTS idx_prompt_history_prompt
    ON prompt_history(prompt_id);

CREATE INDEX IF NOT EXISTS idx_prompt_history_version
    ON prompt_history(media_id, version DESC);

CREATE UNIQUE INDEX IF NOT EXISTS idx_prompt_history_media_version
    ON prompt_history(media_id, version);



CREATE TABLE IF NOT EXISTS jobs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id            INTEGER NOT NULL,
    prompt_id           INTEGER,
    type                TEXT NOT NULL,        -- regenerate, variation, upscale, other
    status              TEXT NOT NULL,        -- pending, running, done, error
    created_at          TEXT NOT NULL,
    updated_at          TEXT,
    forge_request_json  TEXT,
    forge_response_json TEXT,
    error_message       TEXT,

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (prompt_id) REFERENCES prompts(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_status
    ON jobs(status);

CREATE INDEX IF NOT EXISTS idx_jobs_status_created
    ON jobs(status, created_at);

CREATE INDEX IF NOT EXISTS idx_jobs_media
    ON jobs(media_id);


CREATE TABLE IF NOT EXISTS ollama_results (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id    INTEGER NOT NULL,
    mode        TEXT NOT NULL,
    model       TEXT NOT NULL,
    title       TEXT,
    caption     TEXT,
    score       INTEGER,
    contradictions TEXT,
    missing     TEXT,
    rationale   TEXT,
    raw_json    TEXT,
    raw_text    TEXT,
    parse_error INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL,
    meta        TEXT,

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ollama_results_media
    ON ollama_results(media_id);

CREATE INDEX IF NOT EXISTS idx_ollama_results_mode
    ON ollama_results(mode);

CREATE INDEX IF NOT EXISTS idx_ollama_results_media_mode
    ON ollama_results(media_id, mode);


CREATE TABLE IF NOT EXISTS media_lifecycle_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id       INTEGER NOT NULL,
    event_type     TEXT NOT NULL, -- status_change, delete_request, quality_eval
    from_status    TEXT,
    to_status      TEXT,
    quality_status TEXT,
    quality_score  REAL,
    rule           TEXT,
    reason         TEXT,
    actor          TEXT,
    created_at     TEXT NOT NULL,

    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_media_lifecycle_events_media
    ON media_lifecycle_events(media_id);

CREATE INDEX IF NOT EXISTS idx_media_lifecycle_events_type
    ON media_lifecycle_events(event_type);



CREATE TABLE IF NOT EXISTS collections (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS collection_media (
    collection_id   INTEGER NOT NULL,
    media_id        INTEGER NOT NULL,
    PRIMARY KEY (collection_id, media_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id)      REFERENCES media(id)       ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_collection_media_collection
    ON collection_media(collection_id);

CREATE INDEX IF NOT EXISTS idx_collection_media_media
    ON collection_media(media_id);



CREATE TABLE IF NOT EXISTS import_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    path        TEXT NOT NULL,
    status      TEXT NOT NULL,      -- imported, skipped_duplicate, error
    message     TEXT,
    created_at  TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_import_log_status
    ON import_log(status);

CREATE INDEX IF NOT EXISTS idx_import_log_created_at
    ON import_log(created_at);


CREATE TABLE IF NOT EXISTS schema_migrations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    version     TEXT NOT NULL UNIQUE,
    applied_at  TEXT NOT NULL,
    description TEXT
);


CREATE TABLE IF NOT EXISTS consistency_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    check_name  TEXT NOT NULL,
    severity    TEXT NOT NULL,
    message     TEXT NOT NULL,
    created_at  TEXT NOT NULL
);


CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    action      TEXT NOT NULL,
    entity_type TEXT,
    entity_id   INTEGER,
    details_json TEXT,
    actor_ip    TEXT,
    actor_key   TEXT,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS media_meta (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id    INTEGER NOT NULL,
    source      TEXT NOT NULL,
    meta_key    TEXT NOT NULL,
    meta_value  TEXT,
    created_at  TEXT NOT NULL,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_media_meta_media
    ON media_meta(media_id);
