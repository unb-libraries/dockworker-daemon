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
        $this->renderAllSnapshotFiles($options['env']);
    }

    /**
     * Installs a snapshot into a running instance.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $source-env
     *   The environment to install the snapshot from.
     * @option string $target-env
     *   The environment to install the snapshot in.
     *
     * @command snapshot:install
     * @usage --source-env=prod --target-env=
     */
    public function installSnapshot(
        array $options = [
            'source-env' => 'prod',
            'target-env' => 'local',
        ]
    ): void {
        $this->validateCommandOptions($options);
        $this->initSnapshotCommand($options['source-env']);
        $this->initContainerExecCommand($this->dockworkerIO, $options['target-env']);
        $this->renderAllSnapshotFiles($options['source-env']);

        $tmp_path = self::createTemporaryLocalStorage();
        $this->validateDiskSpaceOnDevices(
            $tmp_path,
            $this->getDeployedContainer(
                $this->dockworkerIO,
                $options['target-env']
            )
        );

        $this->dockworkerIO->warning(
            sprintf(
                'A snapshot installation is extremely destructive and will overwrite all data in the [%s] environment.',
                $options['target-env']
            )
        );

        if (
            $this->dockworkerIO->confirm(
                sprintf(
                    'Are you sure you want to install the above-listed [%s] snapshot into [%s]?',
                    $options['source-env'],
                    $options['target-env']
                )
            )
        ) {
            $this->copySnapshotsToLocalTmp($tmp_path);
            $container = $this->moveSnapshotsToContainer(
                $tmp_path,
                $options['target-env']
            );
            $this->executeImportScript($container);
        }
    }

    /**
     * Validates that there is enough disk space to install the snapshot.
     *
     * @param string $local_tmp_path
     *  The path to the local dir the snapshot will be copied to.
     */
    protected function validateDiskSpaceOnDevices($local_tmp_path): void
    {
        $total_bytes_needed = 0;
        foreach ($this->snapshotFiles as $snapshot_file) {
            $total_bytes_needed += $snapshot_file[1];
        }
        $estimate_bytes_needed = $total_bytes_needed * 4;
        if (disk_free_space($local_tmp_path) < $estimate_bytes_needed) {
            $this->dockworkerIO->error(
                sprintf(
                    'There is likely not enough free space in %s to stage the snapshot. You need an (minimum estimate of) %s free.',
                    sys_get_temp_dir(),
                    self::bytesToHumanString($estimate_bytes_needed)
                )
            );
            exit(1);
        }
    }

    /**
     * Displays all snapshot files for the given environment.
     *
     * @param string $env
     *   The environment to display the snapshots for.
     */
    protected function renderAllSnapshotFiles($env): void
    {
        if (empty($this->snapshotFiles)) {
            $this->dockworkerIO->error(
                sprintf(
                    'There are no snapshots available for %s.',
                    $env
                )
            );
            exit(1);
        }
        $this->displaySnapshotFiles($env, $this->dockworkerIO);
    }

    /**
     * Validates the command option for unreasonable requests.
     *
     * @param array $options
     *   The command options.
     */
    protected function validateCommandOptions(array $options): void
    {
        if ($options['target-env'] === $options['source-env']) {
            $this->dockworkerIO->warning(
                sprintf(
                    'The source [%s] and destination environments [%s] are the same.',
                    $options['source-env'],
                    $options['target-env']
                )
            );
            exit(0);
        }

        if ($options['target-env'] === $options['source-env']) {
            $this->dockworkerIO->warning(
                sprintf(
                    'The source [%s] and destination environments [%s] are the same.',
                    $options['source-env'],
                    $options['target-env']
                )
            );
            exit(0);
        }
    }

    /**
     * Executes the generalized import script in the container.
     *
     * @param [type] $container
     */
    protected function executeImportScript($container): void
    {
        $this->dockworkerIO->title('Installing Snapshot in Container');
        $container->run(
            ['/scripts/importData.sh', '/tmp/snapshot'],
            $this->dockworkerIO,
            TRUE
        );
    }

    /**
     * 'Moves' the snapshot files to the container.
     *
     * @param string $snapshot_path
     *   The path to the snapshot dir.
     * @param string $env
     *   The environment to copy the snapshot to.
     *
     * @return DockerContainer|null
     *   The container object, or null if none are available.
     */
    protected function moveSnapshotsToContainer(
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
            unlink($snapshot_path . '/' . $snapshot_file[0]);
        }
        return $container;
    }

    /**
     * Copies the snapshot files from the storage server to the local tmp dir.
     *
     * @param string $tmp_path
     *   The path to the local tmp dir.
     */
    protected function copySnapshotsToLocalTmp(string $tmp_path): void
    {
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
