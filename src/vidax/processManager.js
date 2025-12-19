const {
  AssetError,
  ensureAllAssets,
  listAssetStatuses,
  loadAssetManifest,
  resolveAssetsConfigPath,
} = require('../setup/assets');

class ProcessManager {
  constructor(stateDir) {
    this.stateDir = stateDir;
    this.cachedManifestPath = null;
    this.cachedManifest = null;
  }

  async loadManifest() {
    const manifestPath = await resolveAssetsConfigPath(this.stateDir);
    if (manifestPath !== this.cachedManifestPath || !this.cachedManifest) {
      this.cachedManifest = await loadAssetManifest(manifestPath);
      this.cachedManifestPath = manifestPath;
    }
    return { manifest: this.cachedManifest, manifestPath: this.cachedManifestPath };
  }

  async ensureAssets() {
    const { manifest } = await this.loadManifest();
    return ensureAllAssets(manifest, this.stateDir);
  }

  async status() {
    const { manifest } = await this.loadManifest();
    return listAssetStatuses(manifest, this.stateDir);
  }

  async startComfyUI() {
    const ensured = await this.ensureAssets();
    const missing = ensured.filter((asset) => asset.status !== 'present');
    if (missing.length > 0) {
      throw new AssetError('assets missing; ComfyUI start blocked', 'INPUT_NOT_FOUND', { statuses: missing });
    }

    return { status: 'ready', assets: ensured };
  }
}

module.exports = { ProcessManager };
