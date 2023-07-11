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
     * Monitors the local deployment progress.
     */
    private function monitorLocalStartupProgress(): void
    {
        $this->dockworkerIO->section("[local] Application Deployment");
        $cmd = $this->startLocalDeploymentLogFollowingCommand();
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
}
