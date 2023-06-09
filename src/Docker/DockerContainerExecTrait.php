<?php

namespace Dockworker\Docker;

use Dockworker\Cli\DockerCliTrait;
use Dockworker\Cli\KubectlCliTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\K8s\DeployedK8sResourcesTrait;
use Robo\Robo;

/**
 * Provides methods for executing commands inside deployed docker containers.
 */
trait DockerContainerExecTrait
{
    use DeployedK8sResourcesTrait;
    use DeployedLocalResourcesTrait;
    use DockerCliTrait;
    use KubectlCliTrait;

    /**
     * Executes a command in the deployed container.
     *
     * @param string $env
     *   The environment to execute the command in.
     * @param array $command
     *   The command to execute.
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $title
     *   The title to display.
     * @param string $message
     *   The message to display.
     * @param bool $init
     *   Whether to initialize the command.
     * @param bool $use_tty
     *   TRUE to attach to a TTY for the command.
     *
     * @return array
     *   An array containing the container and the command result.
     */
    protected function executeContainerCommand(
        string $env,
        array $command,
        DockworkerIO $io,
        string $title = '',
        string $message = '',
        bool $init = true,
        bool $use_tty = true,
        string $name = 'default'
    ): array {
        if ($init) {
            $this->initContainerExecCommand($io, $env);
        }
        $container = $this->getDeployedContainer(
            $io,
            $env,
            false,
            true,
            $name
        );
        if (!empty($title)) {
            $io->title($title);
        }
        if (!empty($message)) {
            $io->say($message);
        }
        $cmd = $container->run(
            $command,
            $io,
            $use_tty
        );
        return [$container, $cmd];
    }

    /**
     * Executes a set of shell commands in the deployed container.
     *
     * @param string $env
     *   The environment to execute the command in.
     * @param array $commands
     *   The commands to execute.
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $title
     *   The title to display.
     */
    protected function executeContainerCommandSet(
        string $env,
        array $commands,
        DockworkerIO $io,
        string $title = '',
    ): void {
        $first_command = true;
        foreach ($commands as $command) {
            if ($first_command) {
                $title_string = $title;
                $needs_init = true;
                $first_command = false;
            } else {
                $title_string = '';
                $needs_init = false;
            }
            $this->executeContainerCommand(
                $env,
                $command['command'],
                $io,
                $title_string,
                $command['message'] ?? '',
                $needs_init,
                $command['use_tty'] ?? true
            );
        }
    }

    /**
     * Initializes the command and executes all preflight checks.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $env
     *   The environment to initialize the command for.
     */
    protected function initContainerExecCommand(
        DockworkerIO $io,
        string $env
    ): void {
        if ($env === 'local') {
            $this->registerDockerCliTool($io);
            $this->enableLocalResourceDiscovery();
        } else {
            $this->registerKubectlCliTool($io);
            $this->enableK8sResourceDiscovery();
        }
        $this->checkPreflightChecks($io);
        $this->discoverDeployedResources(
            $io,
            Robo::config(),
            $env
        );
    }

    /**
     * Initializes, performs container discovery, returning a container.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $env
     *   The environment to initialize.
     *
     * @return \Dockworker\Docker\DockerContainer|null
     *   The container.
     */
    protected function initGetDeployedContainer(
        DockworkerIO $io,
        string $env
    ): DockerContainer|null {
        $this->initContainerExecCommand($io, $env);
        return $this->getDeployedContainer(
            $io,
            $env
        );
    }
}
