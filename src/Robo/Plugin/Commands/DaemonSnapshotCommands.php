<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\DockworkerDaemonCommands;
use Dockworker\Snapshot\SnapshotTrait;
use Dockworker\Storage\ApplicationLocalDataStorageTrait;

/**
 * Provides commands for interacting with snapshots of the application's data.
 */
class DaemonSnapshotCommands extends DockworkerDaemonCommands
{
    use ApplicationLocalDataStorageTrait;
    use SnapshotTrait;

    protected $snapshotHost;
    protected $snapshotPath;

    /**
     * Shows the current snapshots for this application.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $env
     *   The environment to show the snapshots for.
     *
     * @command snapshot:list
     * @usage --env=prod
     */
    public function listSnapshots(
        array $options = [
            'env' => 'prod',
        ]
    ): void {
        $this->initSnapshotCommand($options['env']);
        $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                $this->snapshotHost . ':' . $this->snapshotPath . '/' . $options['env'] . '/',
            ],
            $this->dockworkerIO,
            null,
            'Snapshot List',
            '',
            true,
            5.0
        );
    }

    protected function initSnapshotCommand(string $env): void
    {
        $this->initRsyncCommand($this->dockworkerIO, $env);
        $this->initSnapshotConfig();
        $this->registerPreflightSnapshotConnectionTest();
        $this->checkPreflightChecks($this->dockworkerIO);
    }

    protected function initSnapshotConfig(): void
    {
        $this->snapshotHost = $this->getSetApplicationLocalDataConfigurationItem(
            'snapshot',
            'host',
            'Snapshot Hostname',
            'retribution.hil.unb.ca',
            'Enter the hostname to retrieve the snapshots from. This is the hostname of the server that the snapshots are stored on. Before adding this value, it is important that you can currently SSH into this server without a password.',
            [],
            'SNAPSHOT_SERVER_HOSTNAME'
        );
        $this->snapshotPath = $this->getSetApplicationLocalDataConfigurationItem(
            'snapshot',
            'path',
            'Snapshot Path on Host',
            "/mnt/storage0/KubeNFS/$this->applicationSlug/snapshot",
            'Enter the path on the snapshot host where the snapshots for this application are stored. This path should contain a sub-directory for each environment.',
            [],
            'SNAPSHOT_SERVER_PATH'
        );
    }

    protected function registerPreflightSnapshotConnectionTest(): void
    {
        $this->registerNewPreflightCheck(
            'Testing connection to snapshot server',
            $this->getCliToolPreflightCheckCommand(
                $this->cliTools['rsync'],
                [
                  $this->snapshotHost . ':/',
                ],
                'rsync',
                5.0
            ),
            'mustRun',
            [],
            'getOutput',
            [],
            'etc',
            sprintf(
                'Could not establish a connection to the snapshot server. Please ensure that you can SSH into the server without a password (i.e. \'ssh %s\').',
                $this->snapshotHost
            )
        );
    }
}
