#!/usr/bin/env node
const { program } = require('commander');
const { runDoctor } = require('../src/setup/doctor');
const { runInstall } = require('../src/setup/install');

program
  .name('va')
  .description('VIDAX setup utilities');

program
  .command('doctor')
  .description('Check runtime dependencies')
  .action(async () => {
    const exitCode = await runDoctor();
    process.exit(exitCode);
  });

program
  .command('install')
  .description('Prepare state directory and ensure assets')
  .action(async () => {
    const exitCode = await runInstall();
    process.exit(exitCode);
  });

program.parseAsync(process.argv);
