<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIO;
use Dockworker\K8s\K8sJobTrait;
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
    use K8sJobTrait;

    /**
     * Shows the named snapshots for this application.
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
        $env = $options['env'];
        $this->initSnapshotConnection($env);
        $names = $this->listSnapshotNames($env);
        if (empty($names)) {
            $this->dockworkerIO->error(
                sprintf('There are no snapshots available for [%s].', $env)
            );
            exit(1);
        }

        // Pull every manifest in a single transfer, then render one row per
        // snapshot. A snapshot without a parseable manifest is shown as such
        // rather than breaking the listing.
        $manifest_dir = $this->fetchAllManifests($env);
        $rows = [];
        foreach ($names as $name) {
            $manifest = $this->decodeManifestFile(
                $manifest_dir . '/' . $name . '/snapshot.json'
            );
            if ($manifest === null) {
                $rows[] = [$name, '(no manifest)', '', '', ''];
                continue;
            }
            $rows[] = [
                $name,
                (string) ($manifest['created'] ?? ''),
                $this->summarizeSnapshotSize($manifest),
                (string) ($manifest['created_by'] ?? ''),
                (string) ($manifest['description'] ?? ''),
            ];
        }
        $this->dockworkerIO->title("[$env] Snapshots");
        $this->dockworkerIO->table(
            ['Name', 'Created (UTC)', 'Size', 'Created By', 'Description'],
            $rows
        );
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
     * @option string $name
     *   The name of the snapshot to install.
     * @option bool $no-files
     *   Do not install any files.
     * @option bool $force
     *   Install even if the snapshot has no valid manifest (advanced).
     *
     * @command snapshot:install
     * @usage --source-env=prod --target-env= --name=nightly
     */
    public function installSnapshot(
        array $options = [
            'source-env' => 'prod',
            'target-env' => 'local',
            'name' => 'nightly',
            'no-files' => false,
            'force' => false,
        ]
    ): void {
        $this->validateSnapshotName($options['name']);
        $this->validateCommandOptions($options);

        // Establish the connection and read the manifest first. The manifest is
        // the snapshot's commit marker: its absence means the snapshot is in
        // progress, failed, or not yet migrated, so it is not safe to install.
        $this->initSnapshotConnection($options['source-env']);
        $manifest = $this->readManifest($options['name']);
        if ($manifest === null && !$options['force']) {
            $this->dockworkerIO->error(
                sprintf(
                    "The [%s] snapshot '%s' has no valid manifest (snapshot.json). It may be in progress, failed, or not yet migrated. Re-run with --force to install it anyway.",
                    $options['source-env'],
                    $options['name']
                )
            );
            exit(1);
        }

        // Decide whether to skip files: an explicit --no-files, or a manifest
        // that records the snapshot was taken without files.
        $files_to_skip = [];
        if ($options['no-files']) {
            $this->dockworkerIO->say(
                'Skipping file installation as requested.'
            );
            $files_to_skip = ['files.tar.gz'];
        } elseif (
            $manifest !== null &&
            array_key_exists('has_files', $manifest) &&
            empty($manifest['has_files'])
        ) {
            $this->dockworkerIO->say(
                'This snapshot contains no files (per its manifest); installing the database only.'
            );
            $files_to_skip = ['files.tar.gz'];
        }

        $this->selectSnapshot(
            $options['source-env'],
            $options['name'],
            $files_to_skip
        );
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
                    "Are you sure you want to install the above-listed [%s] snapshot '%s' into [%s]?",
                    $options['source-env'],
                    $options['name'],
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
     * Triggers a named snapshot of a deployed environment.
     *
     * Launches a one-off Kubernetes Job derived from the environment's snapshot
     * CronJob, overriding the snapshot name. The Job runs on the backup node
     * with the snapshot volume mounted, exactly like the scheduled nightly, so
     * the snapshot is written server-side without routing data through this
     * machine.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $env
     *   The deployed environment to snapshot.
     * @option string $name
     *   The name to give the snapshot.
     * @option bool $no-files
     *   Do not include the filestore in the snapshot.
     * @option string $description
     *   An optional description to record in the snapshot manifest.
     * @option bool $wait
     *   Wait for the snapshot Job to complete before returning.
     *
     * @command snapshot:create
     * @usage --env=prod --name=pre-upgrade
     */
    public function createSnapshot(
        array $options = [
            'env' => 'prod',
            'name' => '',
            'no-files' => false,
            'description' => '',
            'wait' => false,
        ]
    ): void {
        $env = $options['env'];
        $name = $options['name'];
        $this->validateSnapshotName($name);

        if ($env === 'local') {
            $this->dockworkerIO->error(
                'Snapshots cannot be created for the local environment; snapshot:create targets deployed environments only.'
            );
            exit(1);
        }

        $this->initK8sJobCommand($this->dockworkerIO);

        // Identify the snapshot CronJob. It cannot be found by the shared
        // "component=cronjob" label alone, because sibling CronJobs (e.g. the
        // Drupal cron) carry the same label; the snapshot CronJob is identified
        // by its name.
        $cronjob_name = $this->resolveSnapshotCronJobName($env);

        // Single-flight: refuse if a snapshot Job (nightly or manual) is active.
        // Scoped by the CronJob name prefix so the Drupal cron's Jobs do not
        // count as an active snapshot.
        if ($this->countActiveJobsByNamePrefix($env, $cronjob_name) > 0) {
            $this->dockworkerIO->error(
                sprintf(
                    'A snapshot Job is already running for this application in [%s]. Wait for it to finish before creating another.',
                    $env
                )
            );
            exit(1);
        }

        // Use the CronJob's Job template as the base for the manual Job.
        $job_spec = $this->getCronJobJobSpec($env, $cronjob_name);
        if ($job_spec === null) {
            $this->dockworkerIO->error(
                sprintf(
                    'Could not read the Job template from CronJob [%s] in [%s].',
                    $cronjob_name,
                    $env
                )
            );
            exit(1);
        }
        $job_spec = $this->prepareSnapshotJobSpec($job_spec, $name, $options);

        // Create the Job.
        $job_name = $this->buildSnapshotJobName($cronjob_name, $name);
        $labels = [
            'app.kubernetes.io/component' => 'cronjob',
            'app.kubernetes.io/part-of' => $this->applicationName,
            'lib.unb.ca/snapshot-trigger' => 'manual',
        ];
        $create = $this->createJobFromSpec($env, $job_name, $job_spec, $labels);
        if (!$create->isSuccessful()) {
            $this->dockworkerIO->error(
                sprintf(
                    "Failed to create the snapshot Job in [%s]. You may lack permission to create Jobs in this namespace.\n%s",
                    $env,
                    $create->getErrorOutput()
                )
            );
            exit(1);
        }
        $this->dockworkerIO->success(
            sprintf(
                "Snapshot Job [%s] created in [%s] for snapshot '%s'.",
                $job_name,
                $env,
                $name
            )
        );

        if ($options['wait']) {
            $result = $this->waitForJobCompletion($env, $job_name, $this->dockworkerIO);
            if ($result === 'succeeded') {
                $this->dockworkerIO->success("Snapshot '$name' completed successfully.");
            } elseif ($result === 'failed') {
                $this->dockworkerIO->error(
                    "The snapshot '$name' Job failed. Inspect it with: kubectl logs job/$job_name --namespace=$env"
                );
                exit(1);
            } else {
                $this->dockworkerIO->warning(
                    "Timed out waiting for snapshot '$name'. Track it with: kubectl get job/$job_name --namespace=$env"
                );
            }
        } else {
            $this->dockworkerIO->say(
                sprintf(
                    'Track it with: kubectl get job/%s --namespace=%s (or run snapshot:list --env=%s once it finishes).',
                    $job_name,
                    $env,
                    $env
                )
            );
        }
    }

    /**
     * Resolves the name of the environment's snapshot CronJob.
     *
     * Sibling CronJobs (e.g. the Drupal cron) share the "component=cronjob"
     * label, so the snapshot CronJob is identified by name: the chart names it
     * "<tld-dashed>-snapshot". The conventional name is tried first; if it does
     * not exist (e.g. a custom name), fall back to label discovery filtered to
     * names ending in "-snapshot". Errors if none or several remain.
     *
     * @param string $env
     *   The environment to resolve the CronJob in.
     *
     * @return string
     *   The resolved snapshot CronJob name.
     */
    protected function resolveSnapshotCronJobName(string $env): string
    {
        $derived = str_replace('.', '-', $this->applicationName) . '-snapshot';
        if ($this->getCronJobJobSpec($env, $derived) !== null) {
            return $derived;
        }

        $selector = sprintf(
            'app.kubernetes.io/component=cronjob,app.kubernetes.io/part-of=%s',
            $this->applicationName
        );
        $candidates = array_values(
            array_filter(
                $this->findCronJobsByLabel($env, $selector),
                static fn(string $name): bool => str_ends_with($name, '-snapshot')
            )
        );
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (empty($candidates)) {
            $this->dockworkerIO->error(
                sprintf(
                    'No snapshot CronJob was found in [%s]. Ensure drupal.snapshot is enabled for this environment.',
                    $env
                )
            );
            exit(1);
        }
        $this->dockworkerIO->error(
            sprintf(
                'Multiple snapshot CronJobs were found in [%s]: %s. Cannot determine which to use.',
                $env,
                implode(', ', $candidates)
            )
        );
        exit(1);
    }

    /**
     * Prepares a snapshot Job spec: edits container args and sets safe fields.
     *
     * Starts from the CronJob-derived spec so volumes, node placement, image
     * and env are preserved, then rewrites only the container invocation.
     *
     * @param object $job_spec
     *   The CronJob-derived Job spec (.spec.jobTemplate.spec), decoded as an
     *   object so empty JSON objects round-trip correctly.
     * @param string $name
     *   The snapshot name.
     * @param mixed[] $options
     *   The command options.
     *
     * @return object
     *   The edited Job spec.
     */
    protected function prepareSnapshotJobSpec(object $job_spec, string $name, array $options): object
    {
        $containers = $job_spec->template->spec->containers ?? [];
        if (empty($containers)) {
            $this->dockworkerIO->error('The snapshot CronJob has no containers to run.');
            exit(1);
        }
        // $container is a shared object handle; editing it updates the spec.
        $container = $containers[0];

        // The existing invocation carries the site's file policy (large sites
        // set --no-files). Inspect it to drive the confirmation, but the manual
        // default is to include files unless the user asks otherwise.
        $existing = array_merge(
            $container->command ?? [],
            $container->args ?? []
        );
        $cronjob_skips_files = in_array('--no-files', $existing, true);
        $skip_files = (bool) $options['no-files'];

        if ($cronjob_skips_files && !$skip_files) {
            $this->dockworkerIO->warning(
                "This site's scheduled snapshot skips its filestore (likely because it is very large). This manual snapshot will include files, which may take a very long time and a large amount of space."
            );
            if (!$this->dockworkerIO->confirm('Include files anyway?', false)) {
                exit(0);
            }
        }

        // Rebuild the invocation, preserving the entry script.
        $entry = $existing[0] ?? '/scripts/drupalSnapshotEntry.sh';
        $args = ['--name=' . $name];
        if ($skip_files) {
            $args[] = '--no-files';
        }
        $args[] = '--created-by=dockworker:' . $this->userName;
        if (!empty($options['description'])) {
            $args[] = '--description=' . $options['description'];
        }
        $container->command = [$entry];
        $container->args = $args;

        // Safe Job fields: never retry the heavy dump; self-clean after a day.
        $job_spec->backoffLimit = 0;
        $job_spec->ttlSecondsAfterFinished = 86400;

        return $job_spec;
    }

    /**
     * Builds a unique, valid Kubernetes Job name for a manual snapshot.
     *
     * @param string $cronjob_name
     *   The source CronJob name.
     * @param string $name
     *   The snapshot name.
     *
     * @return string
     *   A DNS-safe Job name, at most 63 characters.
     */
    protected function buildSnapshotJobName(string $cronjob_name, string $name): string
    {
        $prefix = trim(substr($cronjob_name, 0, 38), '-');
        $mid = trim(
            substr(
                strtolower((string) preg_replace('/[^a-z0-9-]+/i', '-', $name)),
                0,
                10
            ),
            '-'
        );
        $job_name = $prefix . '-m-' . $mid . '-' . time();
        return rtrim(substr($job_name, 0, 63), '-');
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
