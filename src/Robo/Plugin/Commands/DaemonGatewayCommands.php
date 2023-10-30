<?php

namespace Dockworker\Robo\Plugin\Commands;

use Dockworker\Docker\DockerContainerExecTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\Ngrok\NgrokTrait;

/**
 * Provides commands for opening a gateway into the application's deployed resources.
 */
class DaemonGatewayCommands extends DockworkerDaemonCommands
{
    use DockerContainerExecTrait;
    use NgrokTrait;

    /**
     * The port to open for the gateway.
     *
     * @var string
     */
    protected string $ngrokPort;

    /**
     * Opens a gateway into the application from the outside world.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $port
     *   The port to open for the gateway.
     * @option string $container
     *   The container in the stack to retrive logs for. Defaults to default.
     * @option string $type
     *   The type of gateway to open. Defaults to http.
     * @option string $env
     *   The environment to display the logs for. Defaults to local.
     *
     * @command gateway:open
     * @usage --env=prod
     */
    public function openGateway(
        array $options = [
            'port' => '',
            'container' => 'default',
            'type' => 'http',
            'env' => 'local',
        ]
    ): void {
        $this->initNgrokCommand($options['env'], false);
        $this->initContainerExecCommand($this->dockworkerIO, $options['env']);
        $this->dockworkerIO->title('Opening Gateway');
        $this->dockworkerIO->block(
            sprintf(
                'Ths command will open an %s gateway from the %s/%s container to the outside world. While this is useful for feature demonstration or debugging, it should not be left running for extended periods!',
                strtoupper($options['type']),
                $options['container'],
                $options['env']
            )
        );

        $this->setNgrokPort($options);
        $this->ngrokRun(
            [
                $options['type'],
                '--authtoken=' . $this->ngrokAuthToken,
                $this->ngrokPort,
            ],
            'Opening gateway...',
            $this->dockworkerIO
        );
    }

    /**
     * Sets the port to open for the gateway.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     */
    protected function setNgrokPort(array $options): void
    {
        if (!empty($options['port'])) {
            $this->ngrokPort = $options['port'];
        } else {
            $this->ngrokPort = $this->dockworkerIO->ask(
                sprintf(
                    'Enter the port to open for %s/%s',
                    $options['container'],
                    $options['env']
                ),
                $this->applicationUuid
            );
        }
    }
}
