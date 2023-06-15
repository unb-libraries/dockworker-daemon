<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIO;
use Robo\Robo;

/**
 * Provides commands for obtaining logs from an application.
 */
class DaemonLogCommands extends DockworkerDaemonCommands
{
    use DockerContainerExecTrait;

    /**
     * Obtains the application's logs.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $container
     *   The container in the stack to retrive logs for.
     * @option string $env
     *   The environment to display the logs for.
     *
     * @command application:logs
     * @aliases logs
     * @usage --env=prod
     */
    public function displayApplicationLogs(
        array $options = [
            'container' => 'default',
            'env' => 'local',
            'only-startup' => false,
            'output-file' => '',
        ]
    ): void {
        $logs = $this->getApplicationLogs(
            $this->dockworkerIO,
            $options['env'],
            $options['container']
        );

        if ($options['only-startup']) {
            if ($options['container'] == 'default') {
                $this->extractStartupLogs($logs);
            } else {
                $this->dockworkerIO->warning("Restricting logs to only-startup is only available for the default container. Ignoring option.");
            }
        }

        if (empty($options['output-file'])) {
            $this->dockworkerIO->title("Logs for $this->applicationName [{$options['env']}/{$options['container']}]");
            $this->dockworkerIO->write($logs);
        } else {
            $this->dockworkerIO->say("Writing [{$options['env']}/{$options['container']}] logs to {$options['output-file']}...");
            file_put_contents($options['output-file'], $logs);
        }
    }

    /**
     * Gets the application's container logs.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $env
     *  The environment to display the logs for.
     * @param string $container
     *  The container to display the logs for.
     *
     * @return string
     *   The container's logs.
     */
    protected function getApplicationLogs(
        DockworkerIO $io,
        string $env,
        string $container
    ): string {
        $this->initContainerExecCommand($io, $env);
        $container_obj = $this->getDeployedContainer(
            $io,
            $env,
            false,
            true,
            $container
        );
        if (empty($container_obj)) {
            $this->dockworkerIO->error("No deployed container '$container' found for $this->applicationName [{$env}].");
            $this->dockworkerIO->say("Available containers:");
            $this->dockworkerIO->block($this->getExistingContainerNames());
            exit(1);
        }
        return $container_obj->logs();
    }

    /**
     * Removes any logs after the deployment finished marker.
     *
     * @param string $logs
     *  The logs to modify.
     */
    protected function extractStartupLogs(&$logs): void
    {
        $parts = explode(
            $this->deploymentFinishedMarker,
            $logs
        );
        if (count($parts) > 1) {
            $logs = $parts[0] . $this->deploymentFinishedMarker;
        }
    }
}
