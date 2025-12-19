const express = require('express');
const { AssetError } = require('../setup/assets');
const { loadVidaxConfig } = require('./config');
const { ProcessManager } = require('./processManager');

function authMiddleware(expectedKey) {
  return (req, res, next) => {
    const provided = req.header('x-api-key') || req.query.api_key;
    if (!expectedKey || provided !== expectedKey) {
      return res.status(401).json({ error: 'unauthorized' });
    }
    next();
  };
}

function mapError(error) {
  if (error instanceof AssetError) {
    if (error.code === 'INPUT_NOT_FOUND') {
      return { status: 404, body: { error: error.message, details: error.meta } };
    }
    if (error.code === 'HASH_MISMATCH' || error.code === 'UNSUPPORTED_FORMAT') {
      return { status: 422, body: { error: error.message, details: error.meta } };
    }
    if (error.code === 'DOWNLOAD_FAILED') {
      return { status: 502, body: { error: error.message, details: error.meta } };
    }
    if (error.code === 'WRITE_FAILED') {
      return { status: 507, body: { error: error.message, details: error.meta } };
    }
  }

  return { status: 500, body: { error: error.message } };
}

async function createApp() {
  const vidaxConfig = await loadVidaxConfig();
  const processManager = new ProcessManager(vidaxConfig.stateDir);
  const app = express();

  app.use(express.json());
  app.use(authMiddleware(vidaxConfig.apiKey));

  app.post('/install', async (req, res) => {
    try {
      const assets = await processManager.ensureAssets();
      return res.status(200).json({ status: 'ok', assets });
    } catch (error) {
      const mapped = mapError(error);
      return res.status(mapped.status).json(mapped.body);
    }
  });

  app.get('/install/status', async (req, res) => {
    try {
      const assets = await processManager.status();
      return res.status(200).json({ assets });
    } catch (error) {
      const mapped = mapError(error);
      return res.status(mapped.status).json(mapped.body);
    }
  });

  app.post('/jobs/:id/start', async (req, res) => {
    try {
      const ensured = await processManager.startComfyUI();
      return res.status(202).json({ status: 'queued', assets: ensured.assets, message: ensured.status });
    } catch (error) {
      const mapped = mapError(error);
      return res.status(mapped.status).json(mapped.body);
    }
  });

  return { app, vidaxConfig };
}

async function startServer() {
  const { app, vidaxConfig } = await createApp();
  const port = vidaxConfig.port || 4111;
  app.listen(port, () => {
    console.log(`VIDAX server listening on port ${port}`);
  });
}

if (require.main === module) {
  startServer().catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}

module.exports = { createApp, startServer };
