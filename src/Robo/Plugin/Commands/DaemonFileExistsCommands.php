<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Robo\Robo;

/**
 * Provides commands for opening a shell into the application's deployed resources.
 */
class DaemonFileExistsCommands extends DockworkerDaemonCommands
{
    use DockerContainerExecTrait;

    /**
     * Tests if a file exists inside the container.
     *
     * @param string $file_path
     *   The path to the file to test.
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $container
     *   The container in the stack to retrive logs for.
     * @option string $env
     *   The environment to display the logs for.
     *
     * @command test:file-exists
     * @hidden
     * @usage --env=prod
     */
    public function testFileExistsInContainer(
        string $file_path,
        array $options = [
            'container' => 'default',
            'env' => 'local',
        ]
    ): void {
        $cmd = $this->executeContainerCommand(
            $options['env'],
            [
                'test',
                '-f',
                $file_path,
            ],
            $this->dockworkerIO,
            'Testing File Exists',
            '',
            true,
            false,
            $options['container']
        );
    }
}
