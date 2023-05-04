<?php

namespace Dockworker;

use Consolidation\AnnotatedCommand\AnnotationData;
use Dockworker\DockworkerApplicationCommands;
use Robo\Robo;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Defines a base abstract class for all dockworker-daemon commands.
 *
 * This is not a command class. It should not contain any hooks or commands.
 */
class DockworkerDaemonCommands extends DockworkerApplicationCommands
{
    /**
     * The user-facing endpoint port for the application.
     *
     * @var string
     */
    protected string $applicationPort;

    /**
     * The maximum time to wait fo the daemon to startup.
     *
     * @var string
     */
    protected string $applicationReadinessTimeout;

    /**
     * The marker in the logs inidicating the container has started up.
     *
     * @var string
     */
    protected string $deploymentFinishedMarker;

    /**
     * @hook pre-init
     */
    public function initOptions(InputInterface $input, AnnotationData $annotationData)
    {
        parent::initOptions($input, $annotationData);
        $this->setDaemonProperties();
    }

    /**
     * Initializes the application's core properties.
     *
     * @throws \Dockworker\DockworkerException
     */
    public function setDaemonProperties(): void
    {
        $config = Robo::config();
        $this->setPropertyFromConfigKey(
            $config,
            'applicationPort',
            'dockworker.application.framework.endpoint.port'
        );
        $this->setPropertyFromConfigKey(
            $config,
            'applicationReadinessTimeout',
            'dockworker.application.framework.endpoint.readiness_timeout'
        );
        $this->setPropertyFromConfigKey(
            $config,
            'deploymentFinishedMarker',
            'dockworker.application.framework.startup_finished_marker'
        );
    }
}
