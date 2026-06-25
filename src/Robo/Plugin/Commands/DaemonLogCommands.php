<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainer;
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
     * @option bool $stderr
     *   Restrict output to the container's stderr stream. Exact for local
     *   Docker; not separable for Kubernetes (see --stdout).
     * @option bool $stdout
     *   Restrict output to the container's stdout stream. Exact for local
     *   Docker; for Kubernetes this returns the runtime-merged stream, as
     *   'kubectl logs' cannot separate stdout from stderr.
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
            'stderr' => false,
            'stdout' => false,
        ]
    ): void {
        $streams = $this->getRequestedLogStream($options);
        $this->warnUnsupportedLogStreams($options, $streams);
        $logs = $this->getApplicationLogs(
            $this->dockworkerIO,
            $options['env'],
            $options['container'],
            $streams
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
     * @param string $streams
     *  Which stream(s) to return. One of the DockerContainer::LOGS_* selectors.
     *
     * @return string
     *   The container's logs.
     */
    protected function getApplicationLogs(
        DockworkerIO $io,
        string $env,
        string $container,
        string $streams = DockerContainer::LOGS_ALL
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
        return $container_obj->logs($streams);
    }

    /**
     * Determines which log stream(s) to display from the command options.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @return string
     *   One of the DockerContainer::LOGS_* selectors. Passing both --stdout and
     *   --stderr (or neither) yields the default of both streams.
     */
    protected function getRequestedLogStream(array $options): string
    {
        if ($options['stdout'] && !$options['stderr']) {
            return DockerContainer::LOGS_STDOUT;
        }
        if ($options['stderr'] && !$options['stdout']) {
            return DockerContainer::LOGS_STDERR;
        }
        return DockerContainer::LOGS_ALL;
    }

    /**
     * Warns when requested stream selectors cannot be fully honored.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     * @param string $streams
     *   The resolved stream selector.
     */
    protected function warnUnsupportedLogStreams(
        array $options,
        string $streams
    ): void {
        if ($streams !== DockerContainer::LOGS_ALL && $options['env'] !== 'local') {
            $this->dockworkerIO->warning("'kubectl logs' cannot separate stdout from stderr; --stdout returns the runtime-merged stream and --stderr will be effectively empty.");
        }
        if ($streams === DockerContainer::LOGS_STDERR && $options['only-startup']) {
            $this->dockworkerIO->warning("The startup marker is emitted on stdout, so --only-startup cannot truncate a stderr-only stream. Showing the full stderr.");
        }
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
