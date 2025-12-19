const crypto = require('node:crypto');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { pipeline } = require('node:stream/promises');
const extract = require('extract-zip');
const fsExtra = require('fs-extra');

class AssetError extends Error {
  constructor(message, code, meta = {}) {
    super(message);
    this.code = code;
    this.meta = meta;
  }
}

function getStateDir() {
  const stateDir = process.env.VA_STATE_DIR || path.join(os.homedir(), '.va');
  return path.resolve(stateDir);
}

async function fileExists(targetPath) {
  try {
    await fsp.access(targetPath);
    return true;
  } catch (error) {
    return false;
  }
}

async function calculateSha256(filePath) {
  const hash = crypto.createHash('sha256');
  const fileStream = fs.createReadStream(filePath);
  return new Promise((resolve, reject) => {
    fileStream.on('data', (data) => hash.update(data));
    fileStream.on('error', (error) => reject(error));
    fileStream.on('end', () => resolve(hash.digest('hex')));
  });
}

function resolveTargetPath(stateDir, dest) {
  if (!dest) {
    throw new AssetError('dest is required for assets', 'UNSUPPORTED_FORMAT');
  }

  const normalized = path.normalize(dest);
  if (path.isAbsolute(normalized) || normalized.startsWith('..')) {
    throw new AssetError('dest must stay inside the state directory', 'UNSUPPORTED_FORMAT', { dest });
  }

  const base = path.join(stateDir, 'state');
  const targetPath = path.join(base, normalized);
  return { base, targetPath };
}

async function resolveAssetsConfigPath(stateDir = getStateDir()) {
  const candidates = [];
  if (process.env.VIDAX_ASSETS_CONFIG) {
    candidates.push(process.env.VIDAX_ASSETS_CONFIG);
  }
  candidates.push(path.join(stateDir, 'state', 'config', 'assets.json'));
  candidates.push(path.join(process.cwd(), 'config', 'assets.json'));

  for (const candidate of candidates) {
    if (candidate && (await fileExists(candidate))) {
      return candidate;
    }
  }

  throw new AssetError('no asset configuration found', 'INPUT_NOT_FOUND');
}

async function loadAssetManifest(manifestPath) {
  try {
    const raw = await fsp.readFile(manifestPath, 'utf8');
    const parsed = JSON.parse(raw);
    parsed.workflows = parsed.workflows || [];
    parsed.models = parsed.models || [];
    parsed.policy = parsed.policy || {};
    return parsed;
  } catch (error) {
    const code = error.code === 'ENOENT' ? 'INPUT_NOT_FOUND' : 'UNSUPPORTED_FORMAT';
    throw new AssetError(`failed to read asset manifest: ${error.message}`, code);
  }
}

async function getAssetStatus(asset, stateDir) {
  const { targetPath } = resolveTargetPath(stateDir, asset.dest);
  const exists = await fileExists(targetPath);
  if (!exists) {
    return { status: 'missing', asset, path: targetPath };
  }

  try {
    const stats = await fsExtra.stat(targetPath);
    if (asset.unpack && stats.isDirectory()) {
      return { status: 'hash_mismatch', asset, path: targetPath, reason: 'expected file but found directory' };
    }
    const hash = await calculateSha256(targetPath);
    if (asset.sha256 && hash !== asset.sha256) {
      return { status: 'hash_mismatch', asset, path: targetPath, hash };
    }
    return { status: 'present', asset, path: targetPath, hash };
  } catch (error) {
    return { status: 'hash_mismatch', asset, path: targetPath, reason: error.message };
  }
}

async function downloadToTemp(url, tmpDir) {
  const response = await fetch(url);
  if (!response.ok || !response.body) {
    throw new AssetError(`download failed with status ${response.status}`, 'INPUT_NOT_FOUND');
  }

  const tmpFilePath = path.join(tmpDir, 'asset');
  const writeStream = fs.createWriteStream(tmpFilePath);
  await pipeline(response.body, writeStream);
  return tmpFilePath;
}

async function unpackArchive(archivePath, targetPath, stripRoot = false) {
  const tempExtract = `${archivePath}.extract`;
  await fsExtra.ensureDir(tempExtract);
  await extract(archivePath, { dir: tempExtract });

  let contentRoot = tempExtract;
  if (stripRoot) {
    const entries = await fsp.readdir(tempExtract);
    if (entries.length === 1) {
      const maybeRoot = path.join(tempExtract, entries[0]);
      const stats = await fsExtra.stat(maybeRoot);
      if (stats.isDirectory()) {
        contentRoot = maybeRoot;
      }
    }
  }

  await fsExtra.ensureDir(path.dirname(targetPath));
  await fsExtra.copy(contentRoot, path.dirname(targetPath), { overwrite: true });

  const exists = await fileExists(targetPath);
  if (!exists) {
    throw new AssetError('archive unpacked but expected file is missing', 'UNSUPPORTED_FORMAT');
  }

  await fsExtra.remove(tempExtract);
}

async function downloadAndPlaceAsset(asset, stateDir) {
  const { targetPath } = resolveTargetPath(stateDir, asset.dest);
  const tempDir = await fsp.mkdtemp(path.join(os.tmpdir(), 'va-asset-'));

  try {
    const archivePath = await downloadToTemp(asset.url, tempDir);
    const downloadHash = await calculateSha256(archivePath);
    if (asset.sha256 && downloadHash !== asset.sha256) {
      throw new AssetError('download hash mismatch', 'HASH_MISMATCH', { expected: asset.sha256, actual: downloadHash });
    }

    if (asset.unpack) {
      await unpackArchive(archivePath, targetPath, Boolean(asset.strip_root));
    } else {
      await fsExtra.ensureDir(path.dirname(targetPath));
      await fsExtra.move(archivePath, targetPath, { overwrite: true });
    }
  } catch (error) {
    if (error instanceof AssetError) {
      throw error;
    }
    if (error.code === 'EACCES' || error.code === 'EPERM') {
      throw new AssetError(`write failed: ${error.message}`, 'WRITE_FAILED');
    }
    throw new AssetError(error.message, 'DOWNLOAD_FAILED');
  } finally {
    await fsExtra.remove(tempDir);
  }
}

async function ensureAllAssets(manifest, stateDir) {
  const policy = {
    on_missing: 'download',
    on_hash_mismatch: 'fail',
    ...(manifest.policy || {}),
  };

  const results = [];
  const categories = [
    ['workflows', manifest.workflows || []],
    ['models', manifest.models || []],
  ];

  for (const [type, assets] of categories) {
    for (const asset of assets) {
      const status = await getAssetStatus(asset, stateDir);
      if (status.status === 'present') {
        results.push({ type, id: asset.id, status: 'present', path: status.path });
        continue;
      }

      if (status.status === 'hash_mismatch' && policy.on_hash_mismatch !== 'download') {
        throw new AssetError('hash mismatch', 'HASH_MISMATCH', { asset });
      }

      if (status.status === 'missing' && policy.on_missing !== 'download') {
        throw new AssetError('asset missing', 'INPUT_NOT_FOUND', { asset });
      }

      await downloadAndPlaceAsset(asset, stateDir);
      const finalStatus = await getAssetStatus(asset, stateDir);
      results.push({ type, id: asset.id, status: finalStatus.status, path: finalStatus.path });
      if (finalStatus.status !== 'present') {
        throw new AssetError('asset verification failed', 'HASH_MISMATCH', { asset });
      }
    }
  }

  return results;
}

async function listAssetStatuses(manifest, stateDir) {
  const results = [];
  const categories = [
    ['workflows', manifest.workflows || []],
    ['models', manifest.models || []],
  ];

  for (const [type, assets] of categories) {
    for (const asset of assets) {
      const status = await getAssetStatus(asset, stateDir);
      results.push({ type, id: asset.id, status: status.status, path: status.path });
    }
  }

  return results;
}

module.exports = {
  AssetError,
  calculateSha256,
  downloadAndPlaceAsset,
  ensureAllAssets,
  fileExists,
  getAssetStatus,
  getStateDir,
  listAssetStatuses,
  loadAssetManifest,
  resolveAssetsConfigPath,
  resolveTargetPath,
};
