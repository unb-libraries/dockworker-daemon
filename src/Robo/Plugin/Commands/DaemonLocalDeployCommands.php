<?php

namespace Dockworker\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\Events\CustomEventAwareInterface;
use Consolidation\AnnotatedCommand\Events\CustomEventAwareTrait;
use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\Core\CommandLauncherTrait;
use Dockworker\Deployment\LocalDeploymentMonitorTrait;
use Dockworker\Docker\DeployedLocalResourcesTrait;
use Dockworker\Docker\DockerComposeTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIOTrait;
use Dockworker\Logs\LogCheckerTrait;
use Dockworker\System\LocalHostFileOperationsTrait;

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
    use LocalDeploymentMonitorTrait;
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
     * Deploys this application locally, removing all existing data if it is currently running.
     *
     * @command application:deploy
     * @aliases deploy redeploy start-over leviosa
     *
     * @throws \Dockworker\DockworkerException
     */
    public function deployApplication(): void
    {
        $this->dockworkerIO->title("Deploying $this->applicationName Locally");
        $this->stopRemoveComposeApplicationData(true);
        $this->startUpLocalApplication();
    }

    /**
     * Stops and removes the application locally
     *
     * @command application:rm
     *
     * @throws \Dockworker\DockworkerException
     */
    public function removeApplication(): void
    {
        $this->dockworkerIO->title("Removing $this->applicationName Local Data");
        $this->stopRemoveComposeApplicationData(false);
    }

    /**
     * Removes the application locally along with its images, volumes and hostfile entries.
     *
     * @command application:cleanup
     * @aliases cleanup
     *
     * @throws \Dockworker\DockworkerException
     */
    public function cleanupApplication(): void
    {
        $this->dockworkerIO->title("Cleaning Up $this->applicationName Local Environment");
        $this->stopRemoveComposeApplicationData(true);
        $this->unSetLocalHostFileEntries();
    }

    /**
     * Restarts the application locally, preserving persistent data.
     *
     * @command application:restart
     * @aliases rebuild restart
     *
     * @throws \Dockworker\DockworkerException
     */
    public function restartApplication(): void
    {
        $this->dockworkerIO->title("Re-Deploying $this->applicationName Locally");
        $this->dockworkerIO->say("Preserving data in database and filesystem");
        $handlers = $this->getCustomEventHandlers('dockworker-pre-local-restart-actions');
        foreach ($handlers as $handler) {
            $handler();
        }
        $this->stopRemoveComposeApplicationData(false);
        $this->startUpLocalApplication();
    }

    /**
     * Starts the application locally.
     *
     * @return void
     */
    public function startUpLocalApplication(): void
    {
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
}
