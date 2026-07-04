<?php

namespace Dockworker\K8s;

use Dockworker\Cli\CliCommand;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\Storage\TemporaryStorageTrait;

/**
 * Provides methods to create and monitor Kubernetes Jobs derived from CronJobs.
 *
 * These methods are deliberately generic (no snapshot-specific knowledge) so
 * they can be promoted to the core dockworker package if other commands need
 * to trigger one-off Jobs from CronJobs.
 *
 * @INTERNAL This trait is intended only to be used by Dockworker commands. It
 * references user properties which are not in its own scope.
 */
trait K8sJobTrait
{
    use KubectlCliTrait;
    use TemporaryStorageTrait;

    /**
     * Registers the kubectl tooling and runs preflight checks for Job commands.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     */
    protected function initK8sJobCommand(DockworkerIO $io): void
    {
        $this->registerKubectlCliTool($io);
        $this->checkPreflightChecks($io);
    }

    /**
     * Finds CronJob names in a namespace matching a label selector.
     *
     * @param string $env
     *   The namespace to search.
     * @param string $selector
     *   The kubectl label selector.
     *
     * @return string[]
     *   The matching CronJob names.
     */
    protected function findCronJobsByLabel(string $env, string $selector): array
    {
        $cmd = $this->kubeCtlRun(
            [
                'get',
                'cronjobs',
                "--namespace=$env",
                '-l',
                $selector,
                '-o',
                'jsonpath={.items[*].metadata.name}',
            ],
            'Discover CronJob by label',
            null,
            30.0,
            false
        );
        $output = trim($cmd->getOutput());
        if ($output === '') {
            return [];
        }
        return explode(' ', $output);
    }

    /**
     * Counts the active (running) Jobs in a namespace matching a selector.
     *
     * @param string $env
     *   The namespace to search.
     * @param string $selector
     *   The kubectl label selector.
     *
     * @return int
     *   The total number of active Job pods across matching Jobs.
     */
    protected function countActiveJobsByLabel(string $env, string $selector): int
    {
        $cmd = $this->kubeCtlRun(
            [
                'get',
                'jobs',
                "--namespace=$env",
                '-l',
                $selector,
                '-o',
                'json',
            ],
            'Check for active Jobs',
            null,
            30.0,
            false
        );
        $data = json_decode($cmd->getOutput(), true);
        $active = 0;
        if (is_array($data)) {
            foreach ($data['items'] ?? [] as $item) {
                $active += (int) ($item['status']['active'] ?? 0);
            }
        }
        return $active;
    }

    /**
     * Retrieves a CronJob's jobTemplate spec as a decoded array.
     *
     * @param string $env
     *   The namespace of the CronJob.
     * @param string $name
     *   The name of the CronJob.
     *
     * @return array<string, mixed>|null
     *   The CronJob's .spec.jobTemplate.spec, or null if unavailable.
     */
    protected function getCronJobJobSpec(string $env, string $name): ?array
    {
        $cmd = $this->kubeCtlRun(
            [
                'get',
                "cronjob/$name",
                "--namespace=$env",
                '-o',
                'json',
            ],
            'Retrieve CronJob template',
            null,
            30.0,
            false
        );
        $data = json_decode($cmd->getOutput(), true);
        if (!is_array($data) || empty($data['spec']['jobTemplate']['spec'])) {
            return null;
        }
        return $data['spec']['jobTemplate']['spec'];
    }

    /**
     * Creates a Kubernetes Job from a prepared Job spec.
     *
     * @param string $env
     *   The namespace to create the Job in.
     * @param string $job_name
     *   The name of the Job.
     * @param array<string, mixed> $job_spec
     *   The Job's .spec (typically derived from a CronJob's jobTemplate.spec).
     * @param array<string, string> $labels
     *   Labels to apply to the Job metadata.
     *
     * @return \Dockworker\Cli\CliCommand
     *   The create command result; inspect isSuccessful() / getErrorOutput().
     */
    protected function createJobFromSpec(
        string $env,
        string $job_name,
        array $job_spec,
        array $labels = []
    ): CliCommand {
        $manifest = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'name' => $job_name,
                'namespace' => $env,
                'labels' => $labels,
            ],
            'spec' => $job_spec,
        ];
        $tmp_path = self::createTemporaryLocalStorage('job');
        $manifest_file = $tmp_path . '/job.json';
        file_put_contents(
            $manifest_file,
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        return $this->kubeCtlRun(
            [
                'create',
                '-f',
                $manifest_file,
                "--namespace=$env",
            ],
            'Create Job',
            null,
            60.0,
            false
        );
    }

    /**
     * Polls a Job until it succeeds, fails, or the timeout elapses.
     *
     * @param string $env
     *   The namespace of the Job.
     * @param string $job_name
     *   The name of the Job.
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to report progress to.
     * @param int $interval
     *   Seconds between polls.
     * @param int $timeout
     *   Maximum seconds to wait.
     *
     * @return string
     *   One of 'succeeded', 'failed', or 'timeout'.
     */
    protected function waitForJobCompletion(
        string $env,
        string $job_name,
        DockworkerIO $io,
        int $interval = 10,
        int $timeout = 7200
    ): string {
        $elapsed = 0;
        while ($elapsed < $timeout) {
            $cmd = $this->kubeCtlRun(
                [
                    'get',
                    "job/$job_name",
                    "--namespace=$env",
                    '-o',
                    'jsonpath={.status.succeeded} {.status.failed}',
                ],
                'Poll Job status',
                null,
                30.0,
                false
            );
            $parts = explode(' ', trim($cmd->getOutput()));
            $succeeded = (int) ($parts[0] ?? 0);
            $failed = (int) ($parts[1] ?? 0);
            if ($succeeded > 0) {
                return 'succeeded';
            }
            if ($failed > 0) {
                return 'failed';
            }
            $io->say("Waiting for Job [$job_name] to complete...");
            sleep($interval);
            $elapsed += $interval;
        }
        return 'timeout';
    }
}
