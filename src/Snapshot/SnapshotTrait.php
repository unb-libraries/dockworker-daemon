<?php

namespace Dockworker\Snapshot;

use Dockworker\Cli\RSyncCliTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\Core\PreFlightCheckTrait;
use Dockworker\Storage\DockworkerPersistentDataStorageTrait;
use Dockworker\System\FileSystemOperationsTrait;
use Drupal\Component\FileSystem\FileSystem;
use Exception;
use Github\AuthMethod;
use Github\Client as GitHubClient;

/**
 * Provides methods to authenticate and interact with a GitHub repo.
 */
trait SnapshotTrait
{
    use FileSystemOperationsTrait;
    use RSyncCliTrait;

    protected $snapshotHost;
    protected $snapshotPath;
    protected $snapshotEnvPath;
    protected $snapshotFiles = [];
    protected $totalSnapshotSize = 0;

    /**
     * Initializes the command and executes all preflight checks.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $env
     *   The environment to initialize the command for.
     */
    protected function initRsyncCommand(
        DockworkerIO $io,
        string $env
    ): void {
        $this->registerRSyncCliTool($io);
    }

    /**
     * Initializes the required bootstrap for a snapshot command.
     *
     * @param string $env
     * @return void
     */
    protected function initSnapshotCommand(string $env): void
    {
        $this->initRsyncCommand($this->dockworkerIO, $env);
        $this->initSnapshotConfig();
        $this->registerPreflightSnapshotConnectionTest();
        $this->snapshotEnvPath = $this->snapshotHost . ':' . $this->snapshotPath . '/' . $env;
        $this->setSnapshotFiles($env);
    }

    /**
     * Sets the total filesize of the snapshot files.
     */
    protected function setSnapshotFileSize(): void
    {
        foreach ($this->snapshotFiles as $snapshot_file) {
            $this->totalSnapshotSize += $snapshot_file[1];
        }
    }

    /**
     * Initializes the configuration required for snapshot commands.
     *
     * @return void
     */
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
            'Enter the path on the snapshot host where the snapshots for this application are stored. This path should contain a sub-directory for each environment (dev, prod).',
            [],
            'SNAPSHOT_SERVER_PATH'
        );
    }

    /**
     * Sets the snapshot files from the storage server.
     *
     * @param string $env
     *  The environment to retrieve the snapshot files for.
     */
    protected function setSnapshotFiles(string $env): void
    {
        $snapshot_output = $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                '-ah',
                '--out-format="%n %l %t"',
                '--dry-run',
                $this->snapshotEnvPath . '/*',
                '.'
            ],
            null,
            null,
            '',
            '',
            false,
            10.0
        );
        $raw_snapshot_list = array_filter(
            explode(
                "\n",
                str_replace(
                    '"',
                    '',
                    $snapshot_output->getOutput()
                )
            )
        );
        foreach ($raw_snapshot_list as $snapshot_file) {
            $this->snapshotFiles[] = explode(' ', $snapshot_file);
        }
    }

    /**
     * Displays the snapshot files for the specified environment.
     *
     * @param string $env
     *   The environment to display the snapshot files for.
     * @param DockworkerIO $io
     *   The IO to use for input and output.
     */
    protected function displaySnapshotFiles(
        string $env,
        DockworkerIO $io
    ): void {
        $formatted_files = $this->snapshotFiles;
        array_walk(
            $formatted_files,
            [$this, 'formatSize']
        );
        $io->title("[$env] Snapshot Files");
        $io->table(
            ['File', 'Size', 'Date', 'Time (UTC)'],
            $formatted_files
        );
    }

    /**
     * Registers a preflight check to ensure that the snapshot server is accessible.
     */
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
                'Could not establish a connection to the snapshot server. Are you on the VPN? Please ensure that you can SSH into the server without a user or password specified in the ssh command (i.e. \'ssh %s\').',
                $this->snapshotHost
            )
        );
    }

    private function formatSize(&$item, $key)
    {
        $item[1] = self::bytesToHumanString(($item[1]));
    }

}
