<?php

namespace Dockworker\Ngrok;

use Dockworker\Cli\CliCommandTrait;
use Dockworker\Cli\NgrokCliTrait;
use Dockworker\IO\DockworkerIO;
use Dockworker\Core\PreFlightCheckTrait;
use Dockworker\Storage\DockworkerPersistentDataStorageTrait;

/**
 * Provides methods to authenticate and interact with an ngrok command.
 */
trait NgrokTrait
{
    use CliCommandTrait;
    use DockworkerPersistentDataStorageTrait;
    use NgrokCliTrait;
    use PreFlightCheckTrait;

    protected $ngrokAuthToken;

    /**
     * Initializes the command and executes all preflight checks.
     *
     * @param \Dockworker\IO\DockworkerIO $io
     *   The IO to use for input and output.
     * @param string $env
     *   The environment to initialize the command for.
     */
    protected function registerNgrokTool(
        DockworkerIO $io,
        string $env
    ): void {
        $this->registerNgrokCliTool($io);
    }

    /**
     * Initializes the required bootstrap for ngrok commands.
     *
     * @param string $env
     * @return void
     */
    protected function initNgrokCommand(string $env, bool $preflight = true): void
    {
        $this->registerNgrokTool($this->dockworkerIO, $env);
        $this->initNgrokConfig();
        if ($preflight) {
            $this->checkPreflightChecks($this->dockworkerIO);
        }
    }

    /**
     * Initializes the configuration required for ngrok commands.
     *
     * @return void
     */
    protected function initNgrokConfig(): void
    {
        $this->ngrokAuthToken = $this->getSetDockworkerPersistentDataConfigurationItem(
            'ngrok',
            'token',
            'Auth Token',
            '',
            'Enter your ngrok personal auth token.',
            [
                [
                    'label' => 'HOWTO',
                    'uri' => 'https://dashboard.ngrok.com/get-started/your-authtoken',
                ],
            ],
            'NGROK_AUTH_TOKEN'
        );

    }
}
