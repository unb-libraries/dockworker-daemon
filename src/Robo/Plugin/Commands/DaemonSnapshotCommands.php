<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIO;
use Dockworker\Snapshot\SnapshotTrait;
use Dockworker\Storage\ApplicationLocalDataStorageTrait;
use Dockworker\Storage\TemporaryStorageTrait;

/**
 * Provides commands for interacting with snapshots of the application's data.
 */
class DaemonSnapshotCommands extends DockworkerDaemonCommands
{
    use ApplicationLocalDataStorageTrait;
    use SnapshotTrait;
    use TemporaryStorageTrait;
    use DockerContainerExecTrait;

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
                $this->snapshotEnvPath . '/',
            ],
            $this->dockworkerIO,
            null,
            'Snapshot List :' . $options['env'],
            '',
            true,
            5.0
        );
    }

    /**
     * Installs a snapshot into a running instance.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $env
     *   The environment to show the snapshots for.
     *
     * @command snapshot:install
     * @usage --env=local
     */
    public function installSnapshot(
        array $options = [
            'env' => 'local',
            'source-env' => 'prod',
        ]
    ): void {
        $this->initSnapshotCommand($options['source-env']);
        $this->initContainerExecCommand($this->dockworkerIO, $options['env']);

        if (empty($this->snapshotFiles)) {
            $this->dockworkerIO->error(
                sprintf(
                    'There are no snapshots available for %s.',
                    $options['source-env']
                )
            );
            exit(1);
        }

        $this->displaySnapshotFiles($options['source-env'], $this->dockworkerIO);
        if ($this->dockworkerIO->confirm(
            sprintf(
                'Are you sure you want to install the %s snapshot into %s?',
                $options['source-env'],
                $options['env']
            )
        ))
        {
            $tmp_path = self::createTemporaryLocalStorage();
            $this->copySnapshotsToLocalTmp($tmp_path);
            $container = $this->copySnapshotsToContainer(
                $tmp_path,
                $options['env']
            );
            $this->executeImportScript($container);
        }
    }

    protected function executeImportScript($container) {
        $this->dockworkerIO->title('Installing Snapshot in Container');
        $cmd = $container->run(
            ['/scripts/importData.sh', '/tmp/snapshot'],
            $this->dockworkerIO,
            TRUE
        );
    }

    protected function copySnapshotsToContainer($tmp_path, $env) {
        $this->dockworkerIO->title('Copying snapshot to container');
        [$container, $cmd] = $this->executeContainerCommand(
            $env,
            ['mkdir', '-p', '/tmp/snapshot'],
            $this->dockworkerIO,
            '',
            '',
            false,
            false
        );
        foreach ($this->snapshotFiles as $snapshot_file)
        {
            $container->copyTo(
                $this->dockworkerIO,
                $tmp_path . '/' . $snapshot_file[0],
                '/tmp/snapshot/'
            );
        }
        return $container;
    }

    protected function copySnapshotsToLocalTmp($tmp_path) {
        $this->dockworkerIO->title('Copying snapshot to local disk');
        foreach ($this->snapshotFiles as $snapshot_file)
        {
            $full_snapshot_path = $this->snapshotEnvPath . '/' . $snapshot_file[0];
            $this->executeCliCommand(
                [
                    $this->cliTools['rsync'],
                    '-ah',
                    $full_snapshot_path,
                    $tmp_path,
                ],
                $this->dockworkerIO,
                null,
                '',
                'Copy From Server: ' . $full_snapshot_path,
                true,
                null
            );
        }
    }
}
