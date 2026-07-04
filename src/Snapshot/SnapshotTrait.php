<?php

namespace Dockworker\Snapshot;

use Dockworker\Cli\RSyncCliTrait;
use Dockworker\Docker\DockerContainer;
use Dockworker\IO\DockworkerIO;
use Dockworker\Core\PreFlightCheckTrait;
use Dockworker\Storage\DockworkerPersistentDataStorageTrait;
use Dockworker\Storage\TemporaryStorageTrait;
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
    use TemporaryStorageTrait;

    protected string $snapshotHost;
    protected string $snapshotPath;

    /**
     * The remote path to the environment's snapshot tree (host:path/env).
     */
    protected string $snapshotEnvBasePath;

    /**
     * The remote path to a specific named snapshot (host:path/env/name).
     */
    protected string $snapshotEnvPath;

    /**
     * The name of the snapshot currently being operated on.
     */
    protected string $snapshotName;

    /**
     * @var array<int, string[]>
     */
    protected array $snapshotFiles = [];

    protected int $totalSnapshotSize = 0;

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
     * Initializes the connection to the snapshot server for an environment.
     *
     * Establishes the rsync tooling, snapshot configuration, preflight
     * reachability check and the base path to the environment's snapshot tree.
     * Callers that operate on a single named snapshot should use
     * initSnapshotCommand(); listing operations use this directly.
     *
     * @param string $env
     *   The environment to initialize the connection for.
     */
    protected function initSnapshotConnection(string $env): void
    {
        $this->initRsyncCommand($this->dockworkerIO, $env);
        $this->initSnapshotConfig();
        $this->registerPreflightSnapshotConnectionTest();
        $this->snapshotEnvBasePath = $this->snapshotHost . ':' . $this->snapshotPath . '/' . $env;
    }

    /**
     * Initializes the required bootstrap for a named snapshot command.
     *
     * @param string $env
     *   The environment to initialize the command for.
     * @param string $name
     *   The name of the snapshot to operate on.
     * @param string[] $exclude_files
     *   An array of files to exclude from operations.
     * @return void
     */
    protected function initSnapshotCommand(string $env, string $name, array $exclude_files = []): void
    {
        $this->initSnapshotConnection($env);
        $this->selectSnapshot($env, $name, $exclude_files);
    }

    /**
     * Selects a named snapshot within an already-connected environment.
     *
     * Sets the remote path to the named snapshot and enumerates its artifacts.
     * Assumes initSnapshotConnection() has already been called for $env.
     *
     * @param string $env
     *   The environment the snapshot belongs to.
     * @param string $name
     *   The name of the snapshot to select.
     * @param string[] $exclude_files
     *   An array of files to exclude from operations.
     */
    protected function selectSnapshot(string $env, string $name, array $exclude_files = []): void
    {
        $this->snapshotName = $name;
        $this->snapshotEnvPath = $this->snapshotEnvBasePath . '/' . $name;
        $this->setSnapshotFiles($env, $exclude_files);
    }

    /**
     * Sets the total filesize of the snapshot files.
     */
    protected function setSnapshotFileSize(): void
    {
        foreach ($this->snapshotFiles as $snapshot_file) {
            $this->totalSnapshotSize += (int) $snapshot_file[1];
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
     * @param string[] $exclude_files
     *   An array of files to exclude from operations.
     */
    protected function setSnapshotFiles(string $env, array $exclude_files = []): void
    {
        // The manifest describes the snapshot; it is never a transferable
        // artifact, so it is always excluded from operations.
        $exclude_files = array_merge(['snapshot.json'], $exclude_files);
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
     * Validates that a snapshot name is a safe, single path segment.
     *
     * A snapshot name becomes a directory segment in the remote rsync path and
     * a kubectl argument, so it must not permit path traversal or shell tricks.
     *
     * @param string $name
     *   The snapshot name to validate.
     */
    protected function validateSnapshotName(string $name): void
    {
        if (
            $name === '' ||
            $name === '.' ||
            $name === '..' ||
            !preg_match('/^[A-Za-z0-9._-]+$/', $name)
        ) {
            $this->dockworkerIO->error(
                sprintf(
                    'Invalid snapshot name [%s]. Names may contain only letters, numbers, dots, dashes and underscores.',
                    $name
                )
            );
            exit(1);
        }
    }

    /**
     * Lists the names of the snapshots available for an environment.
     *
     * Enumerates the sub-directories of the environment's snapshot tree; each
     * one is a named snapshot.
     *
     * @param string $env
     *   The environment to list snapshot names for.
     *
     * @return string[]
     *   The sorted snapshot names found for the environment.
     */
    protected function listSnapshotNames(string $env): array
    {
        // Use -d (non-recursive) so rsync lists only the immediate entries of
        // the environment directory. With -a/-r it would descend into each
        // snapshot and report its artifacts as if they were snapshots too.
        $snapshot_output = $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                '-dh',
                '--out-format="%n"',
                '--dry-run',
                $this->snapshotEnvBasePath . '/',
                '.',
            ],
            null,
            null,
            '',
            '',
            false,
            10.0
        );
        $names = [];
        $raw_list = array_filter(
            explode(
                "\n",
                str_replace('"', '', $snapshot_output->getOutput())
            )
        );
        foreach ($raw_list as $line) {
            $line = trim($line);
            // Snapshots are directories; rsync lists directories with a
            // trailing slash. Ignore any loose files at the environment root.
            if ($line === '' || substr($line, -1) !== '/') {
                continue;
            }
            $name = rtrim($line, '/');
            if ($name === '' || $name === '.' || str_contains($name, '/')) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);
        return $names;
    }

    /**
     * Fetches all snapshot manifests for an environment into a local temp tree.
     *
     * Pulls every <name>/snapshot.json in a single rsync, mirroring the remote
     * directory structure locally, rather than one transfer per snapshot.
     *
     * @param string $env
     *   The environment to fetch manifests for.
     *
     * @return string
     *   The local temp directory containing <name>/snapshot.json for each
     *   snapshot that has a manifest.
     */
    protected function fetchAllManifests(string $env): string
    {
        $tmp_path = self::createTemporaryLocalStorage('manifests');
        $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                '-ahr',
                '--prune-empty-dirs',
                '--include=*/',
                '--include=snapshot.json',
                '--exclude=*',
                $this->snapshotEnvBasePath . '/',
                $tmp_path . '/',
            ],
            null,
            null,
            '',
            '',
            false,
            30.0
        );
        return $tmp_path;
    }

    /**
     * Reads and decodes a single named snapshot's manifest.
     *
     * @param string $name
     *   The snapshot name.
     *
     * @return array<string, mixed>|null
     *   The decoded manifest, or null if none exists or it cannot be parsed.
     */
    protected function readManifest(string $name): ?array
    {
        $tmp_path = self::createTemporaryLocalStorage('manifest');
        $this->executeCliCommand(
            [
                $this->cliTools['rsync'],
                '-ah',
                $this->snapshotEnvBasePath . '/' . $name . '/snapshot.json',
                $tmp_path . '/',
            ],
            null,
            null,
            '',
            '',
            false,
            10.0
        );
        return $this->decodeManifestFile($tmp_path . '/snapshot.json');
    }

    /**
     * Decodes a local manifest file, returning null if missing or invalid.
     *
     * @param string $path
     *   The local path to the manifest file.
     *
     * @return array<string, mixed>|null
     *   The decoded manifest, or null.
     */
    protected function decodeManifestFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
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
        $io->title("[$env] Snapshot '$this->snapshotName' Files");
        $io->table(
            ['File', 'Size', 'Date', 'Time (UTC)'],
            $formatted_files
        );
    }

    /**
     * Displays all snapshot files for the given environment, erroring if none.
     *
     * @param string $env
     *   The environment to display the snapshots for.
     */
    protected function renderAllSnapshotFiles($env): void
    {
        if (empty($this->snapshotFiles)) {
            $this->dockworkerIO->error(
                sprintf(
                    "There are no snapshot artifacts available for [%s] snapshot '%s'.",
                    $env,
                    $this->snapshotName
                )
            );
            exit(1);
        }
        $this->displaySnapshotFiles($env, $this->dockworkerIO);
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

    /**
     * Copies the snapshot files from the storage server to the local tmp dir.
     *
     * @param string $tmp_path
     *   The path to the local tmp dir.
     */
    protected function copySnapshotsToLocalTmp(string $tmp_path): void
    {
        $this->dockworkerIO->title('Copying snapshot to local disk');
        foreach ($this->snapshotFiles as $snapshot_file) {
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
        foreach ($this->snapshotFiles as $snapshot_file) {
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
     * Executes the generalized import script in the container.
     *
     * @param DockerContainer $container
     *   The container to run the import script in.
     * @param string $script
     *   The container-side import script to run. Defaults to the standard
     *   (wrapped) import script; pass the raw variant to skip wrapper steps
     *   such as cache rebuilds.
     */
    protected function executeImportScript(
        DockerContainer $container,
        string $script = '/scripts/importData.sh'
    ): void {
        $this->dockworkerIO->title('Installing Snapshot in Container');
        $container->run(
            [$script, '/tmp/snapshot'],
            $this->dockworkerIO,
            true
        );
    }

    /**
     * Validates that there is enough disk space to install the snapshot.
     *
     * There is an assumption made here that the /tmp folder on the staging
     * filesystem is the same as the /tmp folder in a 'local' container. This is
     * generally true, but may not be in all cases. I don't care to follow this
     * rabbit hole any further.
     *
     * @param string $local_tmp_path
     *  The path to the local dir the snapshot will be copied to.
     */
    protected function validateDiskSpaceOnDevices(
        string $local_tmp_path,
        DockerContainer $container,
        string $target_env
    ): void {
        [$staging_bytes_needed, $inflate_bytes_needed] = $this->estimateNeededSpaceForSnapshotInstall();

        if ($target_env === 'local') {
            $this->validateLocalStagingDiskSpace(
                $local_tmp_path,
                $staging_bytes_needed * 2 + $inflate_bytes_needed
            );
        } else {
            $this->validateLocalStagingDiskSpace(
                $local_tmp_path,
                $staging_bytes_needed
            );
            $this->validateContainerDiskSpace(
                $container,
                $staging_bytes_needed + $inflate_bytes_needed
            );
        }
    }

    protected function validateContainerDiskSpace(
        DockerContainer $container,
        int $bytes_needed
    ): void {
        $command = $container->run(
            ['/scripts/diskFree.sh'],
            null,
            false,
        );
        $free_container_space = $command->getOutput();
        if ($free_container_space < $bytes_needed) {
            $this->dockworkerIO->error(
                sprintf(
                    'There is likely not enough free space in the container to inflate the snapshot. You need an (estimate of) %s free.',
                    self::bytesToHumanString($bytes_needed)
                )
            );
            exit(1);
        }
    }

    /**
     * Validates that there is enough disk space to stage the snapshot.
     *
     * @param string $local_tmp_path
     *  The path to the local dir the snapshot will be copied to.
     * @param int $bytes_needed
     *  The number of bytes needed to stage the snapshot.
     */
    protected function validateLocalStagingDiskSpace(
        string $local_tmp_path,
        int $bytes_needed
    ): void {
        if (disk_free_space($local_tmp_path) < $bytes_needed) {
            $this->dockworkerIO->error(
                sprintf(
                    'There is likely not enough free local space in %s to stage the snapshot. You need an (estimate of) %s free.',
                    sys_get_temp_dir(),
                    self::bytesToHumanString($bytes_needed)
                )
            );
            exit(1);
        }
    }

    /**
     * Estimates the amount of space needed to install the snapshot.
     *
     * @return int[]
     *   An array of the staging bytes needed and the inflated bytes needed.
     */
    protected function estimateNeededSpaceForSnapshotInstall(): array
    {
        $compression_percentages = [
            'files.tar.gz' => 40,
            'db.sql.gz' => 80,
        ];

        $staging_bytes_needed = 0;
        $inflate_bytes_needed = 0;
        foreach ($this->snapshotFiles as $snapshot_file) {
            $snapshot_file_size = (int) $snapshot_file[1];
            if (isset($compression_percentages[$snapshot_file[0]])) {
                $inflate_ratio = 1 / (100 - $compression_percentages[$snapshot_file[0]] / 100);
                $inflate_bytes_needed += $snapshot_file_size * $inflate_ratio;
            } else {
                $inflate_bytes_needed += $snapshot_file_size * 2;
            }
            $staging_bytes_needed += $snapshot_file_size;
        }

        return [(int) $staging_bytes_needed, (int) $inflate_bytes_needed];
    }

    /**
     * Formats the size column of a snapshot file row to a human string.
     *
     * @param string[] $item
     *   The snapshot file row, modified in place.
     * @param int $key
     *   The array key of the row.
     */
    private function formatSize(array &$item, int $key): void
    {
        $item[1] = self::bytesToHumanString((int) $item[1]);
    }

}
