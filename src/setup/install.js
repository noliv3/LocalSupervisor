const path = require('node:path');
const fsExtra = require('fs-extra');
const {
  AssetError,
  ensureAllAssets,
  getStateDir,
  loadAssetManifest,
  resolveAssetsConfigPath,
} = require('./assets');

const REQUIRED_DIRS = [
  path.join('state', 'comfyui', 'workflows'),
  path.join('state', 'comfyui', 'models'),
  path.join('state', 'config'),
];

const CONFIG_EXAMPLES = [
  {
    from: path.join('config', 'vidax.example.json'),
    to: path.join('state', 'config', 'vidax.json'),
  },
  {
    from: path.join('config', 'assets.example.json'),
    to: path.join('state', 'config', 'assets.json'),
  },
  {
    from: path.join('config', 'lipsync.providers.example.json'),
    to: path.join('state', 'config', 'lipsync.providers.json'),
  },
];

async function ensureStructure(baseDir) {
  for (const relative of REQUIRED_DIRS) {
    const target = path.join(baseDir, relative);
    await fsExtra.ensureDir(target);
  }
}

async function copyExamples(baseDir) {
  for (const mapping of CONFIG_EXAMPLES) {
    const source = path.join(process.cwd(), mapping.from);
    const target = path.join(baseDir, mapping.to);
    const exists = await fsExtra.pathExists(target);
    if (!exists && (await fsExtra.pathExists(source))) {
      await fsExtra.ensureDir(path.dirname(target));
      await fsExtra.copy(source, target);
      console.log(`copied default config: ${mapping.to}`);
    }
  }
}

function exitCodeForError(error) {
  if (error instanceof AssetError) {
    if (['INPUT_NOT_FOUND', 'HASH_MISMATCH', 'DOWNLOAD_FAILED', 'UNSUPPORTED_FORMAT'].includes(error.code)) {
      return 20;
    }
    if (error.code === 'WRITE_FAILED') {
      return 60;
    }
  }

  if (error.code === 'EACCES' || error.code === 'EPERM') {
    return 60;
  }

  return 20;
}

async function runInstall() {
  const stateDir = getStateDir();

  try {
    await ensureStructure(stateDir);
    await copyExamples(stateDir);
    const manifestPath = await resolveAssetsConfigPath(stateDir);
    const manifest = await loadAssetManifest(manifestPath);
    const assets = await ensureAllAssets(manifest, stateDir);
    console.log(`assets ensured using manifest: ${manifestPath}`);
    assets.forEach((asset) => {
      console.log(`${asset.status}: ${asset.type}/${asset.id} -> ${asset.path}`);
    });
    return 0;
  } catch (error) {
    console.error(error.message);
    const code = exitCodeForError(error);
    return code;
  }
}

module.exports = { runInstall };
