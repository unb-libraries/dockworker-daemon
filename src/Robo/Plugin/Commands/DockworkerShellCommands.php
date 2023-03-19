<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\Docker\DeployedLocalResourcesTrait;
use Dockworker\DockworkerCommands;
use Dockworker\GitHub\GitHubClientTrait;
use Dockworker\K8s\DeployedK8sResourcesTrait;
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
        $this->executeContainerCommand(
          $options['env'],
          [$this->getApplicationShell()],
          $this->dockworkerIO,
          'Opening Shell',
          sprintf(
            'Opening shell in %s. Type \'exit\' to close.',
            $options['env']
          )
        );
    }

    /**
     * Executes a command in the container.
     */
    protected function executeContainerCommand(
      $env,
      $command,
      $io,
      $title = '',
      $message = '',
      $init = true
    ): void
    {
        if ($init) {
            $this->initShellCommand($env);
        }
        $container = $this->getDeployedContainer(
            $io,
            $env
        );
        if (!empty($title)) {
            $io->title($title);
        }
        if (!empty($message)) {
            $io->say($message);
        }
        $container->run(
          $command,
          $io
        );
    }

    protected function executeContainerCommandSet(
        $env,
        array $commands,
        $io,
        $title = '',
    ): void
    {
        $first_command = true;
        foreach ($commands as $command) {
            if ($first_command) {
                $title_string = $title;
                $needs_init = true;
                $first_command = false;
            }
            else {
                $title_string = '';
                $needs_init = false;
            }
            $this->executeContainerCommand(
              $env,
              $command['command'],
              $io,
              $title_string,
              $command['message'],
              $needs_init
            );
        }
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
