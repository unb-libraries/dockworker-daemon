<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Cli\CliCommand;
use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\Core\CommandLauncherTrait;
use Dockworker\Docker\DockerComposeTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\System\LocalHostFileOperationsTrait;

/**
 * Provides commands for building and deploying the application locally.
 */
class DaemonDeployCommands extends DockworkerDaemonCommands
{
    use CommandLauncherTrait;
    use DockerCliTrait;
    use DockerComposeTrait;
    use DockworkerIOTrait;
    use KubectlCliTrait;
    use LocalHostFileOperationsTrait;

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
        } catch (\Exception $e) {
            $this->dockworkerIO->newLine();
            $this->say("Timeout waiting for $this->applicationFrameworkName!");
            $this->showComposeApplicationLogs();
            $this->dockworkerIO->error("$this->applicationFrameworkName failed to ready after {$this->applicationReadinessTimeout}s.");
            exit(1);
        }
    }

    /**
     * Monitors the local deployment progress.
     *
     * @throws \Dockworker\DockworkerException
     */
    private function monitorLocalStartupProgress(): void
    {
        $this->dockworkerIO->section("[local] Application Deployment");
        $cmd = $this->startLocalDeploymentLogFollowingCommand();
        $error_found = false;
        while (
           !str_contains($cmd->getOutput(), '99_z_report_completion')
        ) {
            if ($this->outputHasErrors($cmd->getOutput())) {
                $error_found = true;
                break;
            }
            usleep(500);
        }
        if ($error_found) {
            $cmd->signal(9);
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
    private function startLocalDeploymentLogFollowingCommand(): CliCommand {
        $cmd = $this->getLocalDeploymentLogFollowingCommand();
        $name = $this->applicationName;
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
     * @return \Dockworker\Cli\CliCommand
     */
    private function getLocalDeploymentLogFollowingCommand($timeout = 300): CliCommand {
        return new CliCommand(
            [
                'docker',
                'compose',
                'logs',
                '-f',
                $this->applicationName,
            ],
            '',
            $this->applicationRoot,
            [],
            null,
            $timeout
        );
    }

    /**
     * Checks if the output has errors.
     *
     * @param string $output
     *   The output to check.
     *
     * @return bool
     *   TRUE if the output has errors, FALSE otherwise.
     */
    private function outputHasErrors(string $output): bool {
        return str_contains($output, 'ERROR');
    }
}
