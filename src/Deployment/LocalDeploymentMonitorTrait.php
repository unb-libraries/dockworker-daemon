<?php

namespace Dockworker\Deployment;

use Dockworker\Cli\CliCommand;
use Dockworker\Logs\LogCheckerTrait;
use Exception;

/**
 * Provides methods to monitor a local application deployment's startup.
 *
 * @INTERNAL This trait is intended only to be used by Dockworker commands. It
 * references properties and methods (e.g. $this->applicationSlug,
 * $this->dockworkerIO, dockerRun(), showComposeApplicationLogs())
 * that are provided by the consuming command class and its other traits.
 */
trait LocalDeploymentMonitorTrait
{
    use LogCheckerTrait;

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
        $finished = false;
        $line_buffer = '';
        $warnings = [];
        $matched_errors = [];
        $partition_state = [];
        while (!$finished) {
            $line_buffer .= $cmd->getIncrementalOutput();
            // Process only complete lines; retain any trailing partial line for
            // the next iteration so a marker/error split across a chunk boundary
            // is classified against its whole line.
            $lines = explode("\n", $line_buffer);
            $line_buffer = array_pop($lines);
            if (!empty($lines)) {
                ['scan' => $scan, 'warnings' => $new_warnings] = $this->partitionLogLines($lines, $partition_state);
                $warnings = array_merge($warnings, $new_warnings);
                if (
                    $scan !== ''
                    && $this->logsHaveErrors(
                        $scan,
                        $errors_pattern,
                        $exceptions_pattern,
                        $matched_errors
                    )
                ) {
                    $error_found = true;
                    break;
                }
                foreach ($lines as $line) {
                    if (str_contains($line, $this->deploymentFinishedMarker)) {
                        $finished = true;
                    }
                }
            }
            // Honor the marker even if it lands on the trailing partial line
            // (no newline yet) so completion can never hang.
            if (
                !$finished
                && str_contains($line_buffer, $this->deploymentFinishedMarker)
            ) {
                $finished = true;
            }
            if (!$finished) {
                usleep(500);
            }
        }
        if ($error_found) {
            $cmd->signal(9);
            $this->reportErrorsInLogs($this->dockworkerIO, $matched_errors);
            $this->reportWarningsInLogs($this->dockworkerIO, $warnings);
            $this->dockworkerIO->error('Application deploy failed.');
            exit(1);
        }
        $cmd->stop(1);
        $this->reportWarningsInLogs($this->dockworkerIO, $warnings);
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
