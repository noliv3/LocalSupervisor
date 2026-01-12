const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const { loadAssetManifest, listAssetStatuses, resolveAssetsConfigPath } = require('./assets');
const { resolveVidaxConfigPath } = require('../vidax/config');

const execFileAsync = promisify(execFile);

async function checkCommand(command, args = [], label) {
  const name = label || command;
  try {
    const { stdout } = await execFileAsync(command, args, { timeout: 5000 });
    const detail = stdout ? stdout.split('\n')[0].trim() : 'available';
    return { name, status: 'ok', detail };
  } catch (error) {
    const reason = error.code === 'ENOENT' ? 'not found in PATH' : error.message;
    return { name, status: 'missing', detail: reason };
  }
}

async function checkVidaxConfig() {
  try {
    const configPath = await resolveVidaxConfigPath();
    return { name: 'vidax config', status: 'ok', detail: configPath };
  } catch (error) {
    return { name: 'vidax config', status: 'missing', detail: error.message };
  }
}

async function checkAssets() {
  try {
    const manifestPath = await resolveAssetsConfigPath();
    const manifest = await loadAssetManifest(manifestPath);
    const statuses = await listAssetStatuses(manifest);
    const results = statuses.map((status) => {
      const label = `${status.type}/${status.id}`;
      if (status.status === 'present') {
        return { name: `asset ${label}`, status: 'ok', detail: status.path };
      }
      return { name: `asset ${label}`, status: 'missing', detail: `${status.status}: ${status.path}` };
    });
    return [
      { name: 'assets manifest', status: 'ok', detail: manifestPath },
      ...results,
    ];
  } catch (error) {
    return [{ name: 'assets manifest', status: 'missing', detail: error.message }];
  }
}

async function runDoctor() {
  const checks = [];
  checks.push({ name: 'node', status: 'ok', detail: process.version });
  checks.push(await checkCommand('ffmpeg', ['-version']));
  checks.push(await checkCommand('ffprobe', ['-version']));
  checks.push(await checkCommand('python3', ['--version'], 'python (optional)'));
  checks.push(await checkVidaxConfig());
  checks.push(...(await checkAssets()));

  let exitCode = 0;
  for (const result of checks) {
    const prefix = result.status === 'ok' ? 'ok' : 'missing';
    console.log(`${prefix}: ${result.name} - ${result.detail}`);
    if (
      (result.name === 'ffmpeg'
        || result.name === 'ffprobe'
        || result.name === 'vidax config'
        || result.name === 'assets manifest'
        || result.name.startsWith('asset '))
      && result.status !== 'ok'
    ) {
      exitCode = 20;
    }
  }

  return exitCode;
}

module.exports = { runDoctor };
