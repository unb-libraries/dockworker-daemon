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
     * @option string $env
     *   The environment to display the logs for.
     *
     * @command application:logs
     * @aliases logs
     * @usage --env=prod
     */
    public function displayApplicationLogs(
        array $options = [
            'env' => 'local',
            'only-startup' => false,
            'output-file' => '',
        ]
    ): void {
        $logs = $this->getApplicationLogs(
            $this->dockworkerIO,
            $options['env']
        );

        if ($options['only-startup']) {
            $this->extractStartupLogs($logs);
        }

        if (empty($options['output-file'])) {
            $this->dockworkerIO->title("Logs for $this->applicationName [$options[env]]");
            $this->dockworkerIO->write($logs);
        }
        else {
            $this->dockworkerIO->say("Writing logs to {$options['output-file']}...");
            file_put_contents($options['output-file'], $logs);
        }
    }

    /**
     * Gets the application's container logs.
     *
     * @return string
     *   The application shell to use.
     */
    protected function getApplicationLogs($io, $env): string
    {
        $this->initContainerExecCommand($io, $env);
        $container = $this->getDeployedContainer(
            $io,
            $env
        );
        return $container->logs();
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
