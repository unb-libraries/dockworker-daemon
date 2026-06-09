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
     * @option bool $no-files
     *   Do not install any files.
     *
     * @command snapshot:install
     * @usage --source-env=prod --target-env=
     */
    public function installSnapshot(
        array $options = [
            'source-env' => 'prod',
            'target-env' => 'local',
            'no-files' => false,
        ]
    ): void {
        $this->validateCommandOptions($options);
        $files_to_skip = [];
        if ($options['no-files']) {
            $this->dockworkerIO->say(
                'Skipping file installation as requested.'
            );
            $files_to_skip = ['files.tar.gz'];
        }
        $this->initSnapshotCommand($options['source-env'], $files_to_skip);
        $this->initContainerExecCommand($this->dockworkerIO, $options['target-env']);
        $this->renderAllSnapshotFiles($options['source-env']);

        $tmp_path = self::createTemporaryLocalStorage();
        $this->validateDiskSpaceOnDevices(
            $tmp_path,
            $this->getDeployedContainer(
                $this->dockworkerIO,
                $options['target-env']
            ),
            $options['target-env']
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
            // Remove the local tmp archive files.
            $this->executeCliCommand(
                ['rm', '-rf', "$tmp_path/*.gz"],
                $this->dockworkerIO,
                null,
                '',
                'Remove Local Archive Files',
                false,
                null
            );
            $this->executeImportScript($container);
            // Now, delete any remaining files in the container dir.
            $this->executeContainerCommand(
                $options['target-env'],
                ['rm', '-rf', '/tmp/snapshot'],
                $this->dockworkerIO,
                '',
                'Remove Container Archive Files',
                false,
                false
            );
        } else {
            $this->dockworkerIO->say('Snapshot installation aborted.');
            // Do not fire hooks.
            exit(0);
        }
    }

    /**
     * Validates the command option for unreasonable requests.
     *
     * @param array<string, mixed> $options
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
            $this->dockworkerIO->warning(
                'This is likely not what you want to do! You will roll back the data to the snapshot time.'
            );
            if (
                !$this->dockworkerIO->confirm(
                    'Are you sure you want to continue anyway?',
                    false
                )
            ) {
                exit(0);
            }
        }

    }
}
