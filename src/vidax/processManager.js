const path = require('node:path');
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const os = require('node:os');
const { spawn, spawnSync } = require('node:child_process');
const {
  AssetError,
  ensureAllAssets,
  listAssetStatuses,
  loadAssetManifest,
  resolveAssetsConfigPath,
} = require('../setup/assets');

class ProcessManager {
  constructor(vidaxConfig) {
    this.vidaxConfig = vidaxConfig;
    this.stateDir = vidaxConfig.stateDir;
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

  async getComfyStatePaths() {
    const stateDir = path.join(this.stateDir, 'state', 'vidax');
    return {
      dir: stateDir,
      file: path.join(stateDir, 'comfyui.json'),
      outLog: path.join(stateDir, 'comfyui.out.log'),
      errLog: path.join(stateDir, 'comfyui.err.log'),
    };
  }

  expandPath(inputPath) {
    if (!inputPath) {
      return inputPath;
    }
    if (inputPath.startsWith('~/')) {
      return path.join(os.homedir(), inputPath.slice(2));
    }
    return inputPath;
  }

  isProcessAlive(pid) {
    if (!pid || pid <= 0) {
      return false;
    }
    if (process.platform === 'win32') {
      const result = spawnSync('tasklist', ['/FI', `PID eq ${pid}`], { encoding: 'utf8' });
      if (result.error || !result.stdout) {
        return false;
      }
      return result.stdout.includes(`${pid}`);
    }
    try {
      process.kill(pid, 0);
      return true;
    } catch (error) {
      return false;
    }
  }

  async readComfyState() {
    const paths = await this.getComfyStatePaths();
    try {
      const raw = await fs.readFile(paths.file, 'utf8');
      const data = JSON.parse(raw);
      return { data, paths };
    } catch (error) {
      return { data: null, paths };
    }
  }

  async status() {
    const { manifest } = await this.loadManifest();
    const assets = await listAssetStatuses(manifest, this.stateDir);
    const { data, paths } = await this.readComfyState();
    const pid = data && typeof data.pid === 'number' ? data.pid : null;
    const running = pid ? this.isProcessAlive(pid) : false;
    return {
      running,
      pid,
      logs: {
        stdout: paths.outLog,
        stderr: paths.errLog,
      },
      assets,
    };
  }



  normalizeCommand(command, args) {
    if (Array.isArray(command)) {
      if (command.length === 0 || typeof command[0] !== 'string' || command[0].trim() === '') {
        throw new Error('ComfyUI command array must contain executable as first item');
      }
      return { executable: command[0], cmdArgs: command.slice(1).map((item) => `${item}`) };
    }

    if (typeof command === 'string' && command.trim() !== '') {
      const trimmed = command.trim();
      if (trimmed.includes(' ')) {
        throw new Error('ComfyUI command with spaces is not allowed; configure command + args explicitly');
      }
      const safeArgs = Array.isArray(args) ? args.map((item) => `${item}`) : [];
      return { executable: trimmed, cmdArgs: safeArgs };
    }

    throw new Error('ComfyUI command missing in VIDAX config');
  }

  rotateLogFile(logPath, maxBytes = 10 * 1024 * 1024, maxBackups = 5) {
    try {
      if (!fsSync.existsSync(logPath)) {
        return;
      }
      const stats = fsSync.statSync(logPath);
      if (!stats.isFile() || stats.size < maxBytes) {
        return;
      }

      for (let i = maxBackups - 1; i >= 1; i -= 1) {
        const src = `${logPath}.${i}`;
        const dst = `${logPath}.${i + 1}`;
        if (fsSync.existsSync(src)) {
          fsSync.renameSync(src, dst);
        }
      }

      fsSync.renameSync(logPath, `${logPath}.1`);
    } catch (error) {
      // keep startup robust even if rotation fails
    }
  }

  async startComfyUI() {
    const ensured = await this.ensureAssets();
    const missing = ensured.filter((asset) => asset.status !== 'present');
    if (missing.length > 0) {
      throw new AssetError('assets missing; ComfyUI start blocked', 'INPUT_NOT_FOUND', { statuses: missing });
    }

    const current = await this.status();
    if (current.running && current.pid) {
      return { status: 'already_running', assets: ensured, pid: current.pid, logs: current.logs };
    }

    const comfyConfig = this.vidaxConfig.comfyui || {};
    const workingDir = this.expandPath(comfyConfig.workingDir);

    if (!workingDir) {
      throw new Error('ComfyUI workingDir missing in VIDAX config');
    }

    const { executable, cmdArgs } = this.normalizeCommand(comfyConfig.command, comfyConfig.args);

    const paths = await this.getComfyStatePaths();
    await fs.mkdir(paths.dir, { recursive: true });

    this.rotateLogFile(paths.outLog);
    this.rotateLogFile(paths.errLog);

    const outFd = fsSync.openSync(paths.outLog, 'a');
    const errFd = fsSync.openSync(paths.errLog, 'a');

    const child = spawn(executable, cmdArgs, {
      cwd: workingDir,
      shell: false,
      detached: true,
      stdio: ['ignore', outFd, errFd],
    });

    fsSync.closeSync(outFd);
    fsSync.closeSync(errFd);

    child.unref();

    const payload = {
      pid: child.pid,
      started_at: new Date().toISOString(),
      command: [executable, ...cmdArgs],
      working_dir: workingDir,
      logs: {
        stdout: paths.outLog,
        stderr: paths.errLog,
      },
    };
    await fs.writeFile(paths.file, JSON.stringify(payload, null, 2));

    return { status: 'started', assets: ensured, pid: child.pid, logs: payload.logs };
  }

  async stopComfyUI() {
    const { data, paths } = await this.readComfyState();
    const pid = data && typeof data.pid === 'number' ? data.pid : null;
    if (!pid || !this.isProcessAlive(pid)) {
      return { status: 'not_running', pid: pid || null };
    }

    if (process.platform === 'win32') {
      spawnSync('taskkill', ['/PID', `${pid}`, '/T', '/F']);
    } else {
      try {
        process.kill(pid, 'SIGTERM');
      } catch (error) {
        // ignore
      }
      await new Promise((resolve) => setTimeout(resolve, 2000));
      if (this.isProcessAlive(pid)) {
        try {
          process.kill(pid, 'SIGKILL');
        } catch (error) {
          // ignore
        }
      }
    }

    try {
      await fs.unlink(paths.file);
    } catch (error) {
      // ignore
    }

    return { status: 'stopped', pid };
  }
}

module.exports = { ProcessManager };
