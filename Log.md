# Änderungsprotokoll

## 2026-01-05
- Dokumentation auf drei Dateien reduziert: Inhalte aus Setup/Assets, Audits, Security-Review und Statusreports in README.md bzw. AGENTS.MD integriert.
- README.md um Dokumentationsstruktur sowie VA/VIDAX-Setup- und Asset-Informationen ergänzt.
- Obsolete Einzel-Dokumente (Audit/Status/Checklists/Setup/Assets) entfernt, um Redundanz zu vermeiden.

## 2026-01-06
- Scanner-Upload-Contract vereinheitlicht: Bilder via `/check` (Feld `image`), Videos/GIFs via `/batch` (Feld `file`), kein Doppel-Upload; Logging erweitert (Endpoint/Feld/Medientyp/Dateigröße/HTTP-Status).
- Video-/GIF-Fallback über FFmpeg-Frames aktiviert (Default `scanner.video_fallback_frames=4`, `scanner.video_fallback_max_dim=1280`), Batch-Antworten werden zusammengeführt, Fehlerdetails in `scan_results.raw_json` persistiert.
