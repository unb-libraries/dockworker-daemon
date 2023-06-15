<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Robo\Robo;

/**
 * Provides commands for opening a shell into the application's deployed resources.
 */
class DaemonShellCommands extends DockworkerDaemonCommands
{
    use DockerContainerExecTrait;

    /**
     * Opens a shell into this application.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $container
     *   The container in the stack to retrive logs for.
     * @option string $env
     *   The environment to display the logs for.
     *
     * @command application:shell
     * @aliases shell
     * @usage --env=prod
     */
    public function openApplicationShell(
        array $options = [
            'container' => 'default',
            'env' => 'local',
        ]
    ): void {
        $this->executeContainerCommand(
            $options['env'],
            [$this->getApplicationShell()],
            $this->dockworkerIO,
            'Opening Shell ',
            sprintf(
                'Opening shell in %s/%s. Type \'exit\' to close.',
                $options['container'],
                $options['env']
            ),
            true,
            true,
            $options['container']
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
            'dockworker.application.shell.path',
            '/bin/sh'
        );
    }
}
