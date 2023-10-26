<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainer;
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
                'Are you sure you want to install the listed [%s] snapshot into [%s]? This action is extremely destructive and will remove all data in the [%s] environment.',
                $options['source-env'],
                $options['env'],
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

    /**
     * Executes the generalized import script in the container.
     *
     * @param [type] $container
     */
    protected function executeImportScript($container): void {
        $this->dockworkerIO->title('Installing Snapshot in Container');
        $container->run(
            ['/scripts/importData.sh', '/tmp/snapshot'],
            $this->dockworkerIO,
            TRUE
        );
    }

    /**
     * Copies the snapshot files to the container.
     *
     * @param string $snapshot_path
     *   The path to the snapshot dir.
     * @param string $env
     *   The environment to copy the snapshot to.
     *
     * @return DockerContainer|null
     *   The container object, or null if none are available.
     */
    protected function copySnapshotsToContainer(
        string $snapshot_path,
        string $env
    ): DockerContainer|null {
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
                $snapshot_path . '/' . $snapshot_file[0],
                '/tmp/snapshot/'
            );
        }
        return $container;
    }

    /**
     * Copies the snapshot files from the storage server to the local tmp dir.
     *
     * @param string $tmp_path
     *   The path to the local tmp dir.
     */
    protected function copySnapshotsToLocalTmp(string $tmp_path): void {
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
