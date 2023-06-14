<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
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
     * @return string
     *   The application shell to use.
     */
    protected function getApplicationLogs($io, $env, $container): string
    {
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
     * @return string
     *   The application shell to use.
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
