<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Robo\Robo;

/**
 * Provides commands for opening a shell into the application's deployed resources.
 */
class DockworkerShellCommands extends DockworkerDaemonCommands
{
    use DockerContainerExecTrait;

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
