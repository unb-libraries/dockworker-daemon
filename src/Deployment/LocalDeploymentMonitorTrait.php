<?php

namespace Dockworker\Deployment;

use Dockworker\Cli\CliCommand;
use Exception;

/**
 * Provides methods to monitor a local application deployment's startup.
 *
 * @INTERNAL This trait is intended only to be used by Dockworker commands. It
 * references properties and methods (e.g. $this->applicationSlug,
 * $this->dockworkerIO, logsHaveErrors(), dockerRun(), showComposeApplicationLogs())
 * that are provided by the consuming command class and its other traits.
 */
trait LocalDeploymentMonitorTrait
{
    /**
     * Monitors the local deployment progress, watching the logs for errors.
     *
     * @param int $timeout
     *   The timeout, in seconds, for following the deployment logs. Defaults to
     *   300; pass a larger value for deployments whose startup (e.g. database
     *   updates and configuration imports) may exceed five minutes.
     */
    protected function monitorLocalStartupProgress(int $timeout = 300): void
    {
        $this->dockworkerIO->section("[local] Application Deployment");
        $cmd = $this->startLocalDeploymentLogFollowingCommand($timeout);
        [$errors_pattern, $exceptions_pattern] = $this->getAllLogErrorStrings();
        $error_found = false;
        $incremental_output = '';
        $matched_errors = [];
        while (
            !str_contains(
                $incremental_output,
                $this->deploymentFinishedMarker
            )
        ) {
            $incremental_output = $cmd->getIncrementalOutput();
            if (
                $this->logsHaveErrors(
                    $incremental_output,
                    $errors_pattern,
                    $exceptions_pattern,
                    $matched_errors
                )
            ) {
                $error_found = true;
                break;
            }
            usleep(500);
        }
        if ($error_found) {
            $cmd->signal(9);
            $this->reportErrorsInLogs($this->dockworkerIO, $matched_errors);
            $this->dockworkerIO->error('Application deploy failed.');
            exit(1);
        }
        $cmd->stop(1);
        $this->say('Container startup complete.');
        $this->dockworkerIO->newLine();
    }

    /**
     * Monitors the local deployment daemon readiness.
     */
    protected function monitorLocalDaemonReadiness(): void
    {
        $this->dockworkerIO->section("Application Readiness");
        $this->say("Waiting for $this->applicationFrameworkName to be ready...");
        $cmd = [
            'run',
            "--network=$this->applicationName",
            '--rm',
            'dokku/wait',
            '-t',
            '300',
            '-c',
            "$this->applicationSlug:$this->applicationPort"
        ];
        try {
            $this->dockerRun(
                $cmd,
                "Waiting for $this->applicationFrameworkName to be ready...",
                null,
                $timeout = (float) $this->applicationReadinessTimeout,
                false
            );
            $this->say("$this->applicationFrameworkName is ready!");
        } catch (Exception $e) {
            $this->dockworkerIO->newLine();
            $this->say("Timeout waiting for $this->applicationFrameworkName!");
            $this->showComposeApplicationLogs();
            $this->dockworkerIO->error(
                "$this->applicationFrameworkName failed to ready after {$this->applicationReadinessTimeout}s."
            );
            exit(1);
        }
    }

    /**
     * Starts the local deployment log following command.
     *
     * @param int $timeout
     *   The timeout, in seconds, for the log following command.
     *
     * @return \Dockworker\Cli\CliCommand
     */
    private function startLocalDeploymentLogFollowingCommand(int $timeout = 300): CliCommand
    {
        $cmd = $this->getLocalDeploymentLogFollowingCommand($this->applicationSlug, $timeout);
        $name = $this->applicationSlug;
        $cmd->start(function ($type, $buffer) use ($name) {
            // Stream is colorless here, so make it easier to read.
            $colored_buffer = str_replace(
                "$name  |",
                "\033[34m$name  |\033[0m",
                $buffer
            );
            echo $colored_buffer;
        });
        return $cmd;
    }

    /**
     * Gets the local deployment log following command.
     *
     * @param string $name
     *   The name of the service to follow logs for.
     * @param int $timeout
     *   The timeout for the command.
     *
     * @return \Dockworker\Cli\CliCommand
     */
    private function getLocalDeploymentLogFollowingCommand(string $name, int $timeout = 300): CliCommand
    {
        return new CliCommand(
            [
                'docker',
                'compose',
                'logs',
                '-f',
                $this->applicationSlug,
            ],
            '',
            $this->applicationRoot,
            [],
            null,
            $timeout
        );
    }
}
