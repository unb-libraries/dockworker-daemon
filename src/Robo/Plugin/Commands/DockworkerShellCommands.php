<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\Docker\DeployedLocalResourcesTrait;
use Dockworker\DockworkerCommands;
use Dockworker\GitHub\GitHubClientTrait;
use Dockworker\K8s\DeployedK8sResourcesTrait;
use Dockworker\StackExchange\StackExchangeTeamClientTrait;
use Robo\Robo;

/**
 * Provides commands for opening a shell into the application's deployed resources.
 */
class DockworkerShellCommands extends DockworkerCommands
{
    use DeployedK8sResourcesTrait;
    use DeployedLocalResourcesTrait;
    use DockerCliTrait;
    use GitHubClientTrait;
    use KubectlCliTrait;
    use StackExchangeTeamClientTrait;

    /**
     * Opens a shell into the application.
     *
     * @option string $env
     *   The environment to open the shell in.
     *
     * @command application:shell
     * @aliases shell
     * @usage --env=prod
     */
    public function openApplicationShell(
      array $options = [
        'env' => 'local',
      ]
    ): void
    {
        $this->initShellCommand($options['env']);
        $container = $this->getDeployedContainer($options['env']);
        $this->dockworkerIO->title('Opening Shell');
        $this->dockworkerIO->info(
            sprintf(
                'Opening shell in %s/%s. Type \'exit\' to close.',
                $options['env'],
                $container->getContainerName()
            )
        );
        $container->run(
            [$this->getApplicationShell()],
            $this->dockworkerIO
        );
    }

    /**
     * Initializes the command and executes all preflight checks.
     *
     * @param string $env
     *   The environment to initialize the command for.
     */
    protected function initShellCommand(string $env): void
    {
        if ($env === 'local') {
            // $this->initGitHubClientApplicationRepo();
            // $this->setStackTeamsClient('unblibsystems');
            $this->registerDockerCliTool($this->dockworkerIO);
            $this->enableLocalResourceDiscovery();
        } else {
            $this->registerKubectlCliTool($this->dockworkerIO);
            $this->enableK8sResourceDiscovery();
        }
        $this->checkPreflightChecks($this->dockworkerIO);
        $this->discoverDeployedResources(
            $this->dockworkerIO,
            Robo::config(),
            $env
        );
    }

    /**
     * Gets the application's shell from configuration.
     *
     * @return string
     *   The application shell to use.
     */
    protected function getApplicationShell(): string
    {
        return $this->getConfigItem(
            Robo::config(),
            'dockworker.application.shell.shell',
            '/bin/sh'
        );
    }
}
