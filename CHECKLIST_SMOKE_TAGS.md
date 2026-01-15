# CHECKLIST_SMOKE_TAGS

> Copy/paste commands (nicht ausführen im Agentenlauf).

## 1) Backfill enqueue (ohne Tags)
```
curl -X POST "http://localhost:8000/index.php" \
  -d "action=backfill_no_tags" \
  -d "backfill_chunk=200" \
  -d "backfill_max=1000" \
  -d "internal_key=<INTERNAL_KEY>"
```

## 2) Jobs prüfen (Scan-Job-Liste)
```
curl -s "http://localhost:8000/index.php?ajax=jobs_list&type=scan&internal_key=<INTERNAL_KEY>" | jq
```

## 3) Cancel eines laufenden Jobs
```
curl -X POST "http://localhost:8000/index.php?ajax=job_cancel&id=<JOB_ID>&internal_key=<INTERNAL_KEY>"
```

## 4) Dedupe prüfen (keine doppelten Rescan-Jobs pro Medium)
```
sqlite3 <DB_PATH> "SELECT media_id, COUNT(*) FROM jobs WHERE type='rescan_media' AND status IN ('queued','running') GROUP BY media_id HAVING COUNT(*) > 1;"
```

## 5) Delete eines fertigen Jobs
```
curl -X POST "http://localhost:8000/index.php?ajax=job_delete&id=<JOB_ID>&internal_key=<INTERNAL_KEY>"
```

## 6) Prune fertiger Scan-Jobs (optional, älter als N Tage)
```
curl -X POST "http://localhost:8000/index.php?ajax=jobs_prune&internal_key=<INTERNAL_KEY>" \
  -d "older_than_days=7" \
  -d "keep_last=5"
```
