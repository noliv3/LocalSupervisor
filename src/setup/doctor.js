const { execFile } = require('node:child_process');
const { promisify } = require('node:util');

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

async function runDoctor() {
  const checks = [];
  checks.push({ name: 'node', status: 'ok', detail: process.version });
  checks.push(await checkCommand('ffmpeg', ['-version']));
  checks.push(await checkCommand('ffprobe', ['-version']));
  checks.push(await checkCommand('python3', ['--version'], 'python (optional)'));

  let exitCode = 0;
  for (const result of checks) {
    const prefix = result.status === 'ok' ? 'ok' : 'missing';
    console.log(`${prefix}: ${result.name} - ${result.detail}`);
    if ((result.name === 'ffmpeg' || result.name === 'ffprobe') && result.status !== 'ok') {
      exitCode = 20;
    }
  }

  return exitCode;
}

module.exports = { runDoctor };
