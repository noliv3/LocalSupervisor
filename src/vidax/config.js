const path = require('node:path');
const fs = require('node:fs/promises');
const fsExtra = require('fs-extra');
const { fileExists, getStateDir } = require('../setup/assets');

function getComfyPaths(stateDir) {
  const base = path.join(stateDir, 'state', 'comfyui');
  return {
    base,
    workflows: path.join(base, 'workflows'),
    models: path.join(base, 'models'),
  };
}

async function resolveVidaxConfigPath(stateDir = getStateDir()) {
  const candidates = [];
  if (process.env.VIDAX_CONFIG) {
    candidates.push(process.env.VIDAX_CONFIG);
  }
  candidates.push(path.join(stateDir, 'state', 'config', 'vidax.json'));
  candidates.push(path.join(process.cwd(), 'config', 'vidax.json'));

  for (const candidate of candidates) {
    if (candidate && (await fileExists(candidate))) {
      return candidate;
    }
  }

  throw new Error('VIDAX config not found');
}

async function loadVidaxConfig(stateDir = getStateDir()) {
  const configPath = await resolveVidaxConfigPath(stateDir);
  const raw = await fs.readFile(configPath, 'utf8');
  const parsed = JSON.parse(raw);

  if (!parsed.apiKey) {
    throw new Error('VIDAX apiKey is required');
  }

  const comfyPaths = getComfyPaths(stateDir);
  await fsExtra.ensureDir(comfyPaths.workflows);
  await fsExtra.ensureDir(comfyPaths.models);

  return { ...parsed, configPath, stateDir, comfyPaths };
}

module.exports = { getComfyPaths, loadVidaxConfig, resolveVidaxConfigPath };
