<?php

namespace Dockworker\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
use Dockworker\Cli\CliCommand;
use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\Core\CommandLauncherTrait;
use Dockworker\Docker\DeployedLocalResourcesTrait;
use Dockworker\Docker\DockerComposeTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Logs\LogCheckerTrait;
use Dockworker\System\LocalHostFileOperationsTrait;
use Exception;

/**
 * Provides commands for building and deploying the application locally.
 */
class DaemonLocalDeployCommands extends DockworkerDaemonCommands implements CustomEventAwareInterface
{
    use CommandLauncherTrait;
    use CustomEventAwareTrait;
    use DeployedLocalResourcesTrait;
    use DockerCliTrait;
    use DockerComposeTrait;
    use DockworkerIOTrait;
    use KubectlCliTrait;
    use LocalHostFileOperationsTrait;
    use LogCheckerTrait;

  /**
   * @hook post-init
   */
    public function initDeployRequirements(): void
    {
        $this->registerDockerCliTool($this->dockworkerIO);
        $this->checkPreflightChecks($this->dockworkerIO);
    }

    /**
     * Deploys this application locally.
     *
     * @command application:deploy
     * @aliases deploy redeploy start-over
     *
     * @throws \Dockworker\DockworkerException
     */
    public function deployApplication(): void
    {
        $this->dockworkerIO->title("Deploying $this->applicationName Locally");
        $this->stopRemoveComposeApplicationData();
        $this->setLocalHostFileEntries();
        $this->setRunOtherCommand(
            $this->dockworkerIO,
            ['theme:build-all']
        );
        $this->buildComposeApplication();
        $this->startComposeApplication();
        $this->monitorLocalStartupProgress();
        $this->monitorLocalDaemonReadiness();
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
            "$this->applicationName:$this->applicationPort"
        ];
        try {
            $this->dockerRun(
                $cmd,
                "Waiting for $this->applicationFrameworkName to be ready...",
                $timeout = $this->applicationReadinessTimeout,
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
     * Monitors the local deployment progress.
     */
    private function monitorLocalStartupProgress(): void
    {
        $this->dockworkerIO->section("[local] Application Deployment");
        $cmd = $this->startLocalDeploymentLogFollowingCommand();
        [$errors_pattern, $exceptions_pattern] = $this->getDeploymentLogErrorStrings();
        $error_found = false;
        $incremental_output = '';
        $matched_error = '';
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
                    $matched_error
                )
            ) {
                $error_found = true;
                break;
            }
            usleep(500);
        }
        if ($error_found) {
            $cmd->signal(9);
            $this->dockworkerIO->error(
                sprintf(
                    'Application deploy failed. [%s] found in output.',
                    trim(
                        str_replace(
                            "$this->applicationName  |",
                            '',
                            $matched_error
                        )
                    )
                )
            );
            exit(1);
        }
        $cmd->stop(1);
        $this->say('Container startup complete.');
        $this->dockworkerIO->newLine();
    }

    /**
     * Starts the local deployment log following command.
     *
     * @return \Dockworker\Cli\CliCommand
     */
    private function startLocalDeploymentLogFollowingCommand(): CliCommand
    {
        $cmd = $this->getLocalDeploymentLogFollowingCommand($this->applicationSlug);
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

    /**
     * Gets the error strings to check for in the deployment logs.
     *
     * Calls the custom event handler dockworker-logs-errors-exceptions.
     * Implementing functions should return an array of two arrays, the first
     * containing error strings, and the second containing exception strings.
     *
     * Implementations wishing to describe the error strings in code should
     * define an associative array with the key being the description and the
     * value being the error string. Then, the array can be cast to a
     * non-associative array using array_values().
     *
     * @return array
     *   An array of error strings and exception strings.
     */
    public function getDeploymentLogErrorStrings(): array
    {
        $errors = [
                'error',
                'fail',
                'fatal',
                'unable',
                'unavailable',
                'unrecognized',
                'unresolved',
                'unsuccessful',
                'unsupported',
        ];
        $exceptions = [];

        $handlers = $this->getCustomEventHandlers('dockworker-logs-errors-exceptions');
        foreach ($handlers as $handler) {
            [$new_errors, $new_exceptions] = $handler();
            $errors = array_merge(
                $errors,
                $new_errors
            );
            $exceptions = array_merge(
                $exceptions,
                $new_exceptions
            );
        }
        return [
            implode('|', $errors),
            implode('|', $exceptions),
        ];
    }
}
