<?php

namespace Dockworker\Robo\Plugin\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Dockworker\Cli\GhCliTrait;
use Dockworker\Deployment\DeploymentWorkflowTrait;
use Dockworker\DockworkerDaemonCommands;
use Dockworker\IO\DockworkerIOTrait;

/**
 * Provides commands for triggering a remote rebuild of a deployed application.
 */
class DeploymentRebuildCommands extends DockworkerDaemonCommands
{
    use DeploymentWorkflowTrait;
    use DockworkerIOTrait;
    use GhCliTrait;

    /**
     * The deployment environment being rebuilt.
     *
     * @var string
     */
    protected string $rebuildEnv = '';

    /**
     * The git branch (ref) the rebuild will be dispatched against.
     *
     * @var string
     */
    protected string $rebuildRef = '';

    /**
     * Triggers a remote rebuild and redeployment of this application.
     *
     * Dispatches the application's GitHub Actions deployment workflow for the
     * requested environment, causing GitHub to rebuild the image and roll the
     * deployment.
     *
     * @param mixed[] $options
     *   The options passed to the command.
     *
     * @option string $env
     *   The deployment environment to rebuild (e.g. dev, prod).
     * @option string $ref
     *   Dispatch against this git branch directly, bypassing environment
     *   resolution. Use for applications with non-standard workflows.
     * @option string $workflow
     *   The workflow file to dispatch.
     * @option bool $yes
     *   Do not prompt for confirmation before triggering the rebuild.
     * @option bool $dry-run
     *   Print the gh command that would run without executing it.
     *
     * @command deployment:rebuild
     * @usage --env=prod
     * @aliases rebuild-deployed
     *
     * @throws \Dockworker\DockworkerException
     */
    public function rebuildDeployedApplication(
        array $options = [
            'dry-run' => false,
            'env' => '',
            'ref' => '',
            'workflow' => 'deployment-workflow.yaml',
            'yes' => false,
        ]
    ): void {
        $repo = "$this->applicationGitHubRepoOwner/$this->applicationGitHubRepoName";
        $command = [
            'workflow',
            'run',
            $options['workflow'],
            '--ref',
            $this->rebuildRef,
            '-R',
            $repo,
        ];

        if ($options['dry-run']) {
            $this->dockworkerIO->title("Dry Run: Rebuild $this->applicationName [$this->rebuildEnv]");
            $this->dockworkerIO->say('Would run: gh ' . implode(' ', $command));
            return;
        }

        $this->dockworkerIO->title("Rebuild $this->applicationName [$this->rebuildEnv]");
        if (
            !$options['yes'] &&
            !$this->dockworkerIO->confirm(
                "Trigger a '$this->rebuildEnv' rebuild of $this->applicationName ($repo @ $this->rebuildRef)?",
                false
            )
        ) {
            $this->dockworkerIO->say('Aborted.');
            return;
        }

        $this->registerGhCliTool($this->dockworkerIO);
        $this->registerGhAuthPreflightCheck($this->dockworkerIO);
        $this->checkPreflightChecks($this->dockworkerIO);

        $this->ghRun(
            $command,
            "Triggering '$this->rebuildEnv' rebuild of $this->applicationName",
            $this->dockworkerIO,
            null,
            false
        );

        $this->dockworkerIO->success("Rebuild of $this->applicationName [$this->rebuildEnv] dispatched.");
        $this->dockworkerIO->say("View runs: gh run list -R $repo --workflow={$options['workflow']}");
    }

    /**
     * Validates the target of the deployment:rebuild command.
     *
     * Resolves the requested environment to the git branch that will be
     * dispatched, storing it for the command to use.
     *
     * @hook validate deployment:rebuild
     */
    public function validateRebuildTarget(CommandData $commandData): void
    {
        $env = $commandData->input()->getOption('env');
        $ref = $commandData->input()->getOption('ref');
        $workflow = $commandData->input()->getOption('workflow');

        // An explicit --ref bypasses environment resolution.
        if (!empty($ref)) {
            $this->rebuildRef = $ref;
            $this->rebuildEnv = !empty($env) ? $env : $ref;
            return;
        }

        $environments = $this->resolveDeploymentEnvironments($workflow);
        if (empty($environments)) {
            $this->dockworkerIO->error("Could not resolve any deployable environments from '$workflow'.");
            $this->dockworkerIO->say('Pass --ref <branch> to dispatch against a branch directly.');
            exit(1);
        }

        $available = implode(', ', array_keys($environments));
        if (empty($env)) {
            $this->dockworkerIO->error('Please specify an environment to rebuild with --env.');
            $this->dockworkerIO->say("Available environments: $available");
            exit(1);
        }
        if (!isset($environments[$env])) {
            $this->dockworkerIO->error("Unknown environment '$env'.");
            $this->dockworkerIO->say("Available environments: $available");
            exit(1);
        }

        $this->rebuildEnv = $env;
        $this->rebuildRef = $environments[$env];
    }
}
