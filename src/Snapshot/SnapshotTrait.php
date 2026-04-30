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
     *   The environment to initialize the command for.
     * @param array $exclude_files
     *   An array of files to exclude from operations.
     * @return void
     */
    protected function initSnapshotCommand(string $env, array $exclude_files = []): void
    {
        $this->initRsyncCommand($this->dockworkerIO, $env);
        $this->initSnapshotConfig();
        $this->registerPreflightSnapshotConnectionTest();
        $this->snapshotEnvPath = $this->snapshotHost . ':' . $this->snapshotPath . '/' . $env;
        $this->setSnapshotFiles($env, $exclude_files);
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
            'vengeance.hil.unb.ca',
            "The snapshot host is the storage server that holds the rsync snapshot tree for this application. It is a separate machine from the application's deployment host, and is the system that all snapshot subcommands (preflight reachability check, file listing, and transfer) target. Every one of those operations invokes rsync over SSH against this host with no username, no identity file, and no port supplied on the command line - the local SSH client must resolve all of that on its own.\n\nBefore saving a value here, you must confirm that running 'ssh <hostname>' against this exact bare hostname - with no 'user@' prefix, no '-i', and no '-p' - drops you into a shell on the remote host without prompting for a password or passphrase. If that command does not succeed cleanly in a fresh terminal, none of the snapshot subcommands will work, and none of the diagnostics from this tool will help you fix it. Test it first; configure this value second.\n\nThe supported way to satisfy that prerequisite is to add a Host block to your '~/.ssh/config' whose alias matches the hostname you enter here, with 'HostName', 'User', and 'IdentityFile' set explicitly (and 'Port' if the server does not listen on 22). The corresponding public key must be present in the remote account's '~/.ssh/authorized_keys', and the private key must either be unencrypted on disk or loaded into 'ssh-agent' - rsync runs non-interactively, so any passphrase prompt will hang the operation indefinitely.",
            [],
            'SNAPSHOT_SERVER_HOSTNAME'
        );
        $this->snapshotPath = $this->getSetApplicationLocalDataConfigurationItem(
            'snapshot',
            'path',
            'Snapshot Path on Host',
            "/mnt/storage0/KubeNFSv2/$this->applicationName/snapshot",
            'Enter the path on the snapshot host where the snapshots for this application are stored. This path should contain a sub-directory for each environment (dev, prod).',
            [],
            'SNAPSHOT_SERVER_PATH'
        );
    }

    /**
     * Sets the snapshot files from the storage server.
     *
     * @param string $env
     *   The environment to retrieve the snapshot files for.
     * @param array $exclude_files
     *   An array of files to exclude from operations.
     */
    protected function setSnapshotFiles(string $env, array $exclude_files = []): void
    {
        $snapshot_output = $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                '-ah',
                '--out-format="%n %l %M"',
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
            $item = explode(' ', $snapshot_file);

            // Column 2 contains date-time, so we need to split it into two columns.
            $values = explode('-', $item[2]);
            $item[2] = $values[0];
            $item[3] = $values[1];
            if (!in_array($item[0], $exclude_files)) {
                $this->snapshotFiles[] = $item;
            }
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
