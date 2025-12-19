# Setup Guide

## State directory
- The state directory defaults to `~/.va` and can be overridden via `VA_STATE_DIR`.
- Standard layout (created by `va install`):
  - `state/comfyui/workflows/`
  - `state/comfyui/models/`
  - `state/config/`

## Configuration paths
- Asset manifest search order:
  1. `VIDAX_ASSETS_CONFIG` (absolute path)
  2. `<VA_STATE_DIR>/state/config/assets.json`
  3. `config/assets.json`
- VIDAX configuration search order:
  1. `VIDAX_CONFIG`
  2. `<VA_STATE_DIR>/state/config/vidax.json`
  3. `config/vidax.json`
- Lipsync providers default: copied from `config/lipsync.providers.example.json` to `<VA_STATE_DIR>/state/config/lipsync.providers.json` when missing.

## CLI flow
- `va doctor` checks for `node`, `ffmpeg`, `ffprobe`, and optional `python3`.
- `va install` creates the state layout, copies example configs when missing, resolves the asset manifest via the search order, and downloads/verifies assets.

## VIDAX server
- Start with `VIDAX_CONFIG=<path> node src/vidax/server.js` when the API key is set.
- ComfyUI paths are derived from the state directory:
  - Workflows: `<VA_STATE_DIR>/state/comfyui/workflows`
  - Models: `<VA_STATE_DIR>/state/comfyui/models`
- Install endpoints (`/install`, `/install/status`) rely on the asset manifest; `/jobs/:id/start` refuses to proceed when assets are missing or invalid.
