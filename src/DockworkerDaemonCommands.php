<?php

namespace Dockworker;

use Dockworker\DockworkerCommands;
use Robo\Robo;

/**
 * Defines a base abstract class for all dockworker-daemon commands.
 *
 * This is not a command class. It should not contain any hooks or commands.
 */
class DockworkerDaemonCommands extends DockworkerCommands
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
     * The UNB Libraries application uuid for the application.
     *
     * @link https://systems.lib.unb.ca/wiki/systems:docker:unique-site-uuids UNB Libraries UUIDs
     * @var string
     */
    protected string $applicationUuid;

    /**
     * DockworkerCommands constructor.
     *
     * @throws \Dockworker\DockworkerException
     */
    public function __construct()
    {
        parent::__construct();
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
            'applicationUuid',
            'dockworker.application.identifiers.uuid'
        );
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
    }
}
