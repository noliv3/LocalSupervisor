# Asset Manifest

## Manifest lookup
1. `VIDAX_ASSETS_CONFIG`
2. `<VA_STATE_DIR>/state/config/assets.json`
3. `config/assets.json`

## Schema
- `policy`: `{ on_missing: "download", on_hash_mismatch: "fail" }` (defaults applied when omitted)
- `workflows[]` / `models[]`: objects with
  - `id`: unique asset identifier
  - `url`: download URL
  - `sha256`: expected hash (checked before placement)
  - `dest`: relative path under `<VA_STATE_DIR>/state/` (e.g., `comfyui/workflows/sample.json`)
  - Optional `unpack: true` to extract archives
  - Optional `strip_root: true` to drop a single top-level directory during unzip

## Hash & verification
- Downloads are hashed before placement; mismatches follow the `policy.on_hash_mismatch` rule.
- Existing files are re-hashed on status checks; mismatches are reported as `hash_mismatch`.

## Unpack behavior
- When `unpack: true`, archives extract into the destination directory; `strip_root` removes a solitary root folder before copying.
- The `dest` path still refers to the expected final file; extraction fails if that file is absent after unpacking.
