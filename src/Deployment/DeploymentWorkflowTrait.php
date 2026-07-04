<?php

namespace Dockworker\Deployment;

use Symfony\Component\Yaml\Yaml;

/**
 * Provides methods to resolve deployment environments from workflow files.
 *
 * The authoritative mapping between a deployment environment and the git branch
 * it deploys from lives in the application's GitHub Actions workflow file, as
 * the 'branch-env-map' and 'deploy-branches' inputs. This is the same data
 * GitHub Actions itself uses, so resolving from it keeps a single source of
 * truth (the 'dockworker.endpoints.env' config is not reliable for this).
 *
 * @INTERNAL This trait is intended only to be used by Dockworker commands. It
 * references user properties (e.g. $this->applicationRoot) which are not in its
 * own scope.
 */
trait DeploymentWorkflowTrait
{
    /**
     * Resolves the deployable environments from an application workflow file.
     *
     * Inverts the workflow's 'branch-env-map' (keyed by branch, valued by
     * environment) and restricts it to the branches listed in
     * 'deploy-branches', so only environments that actually deploy are
     * returned.
     *
     * @param string $workflow_file
     *   The workflow filename, relative to the application's .github/workflows
     *   directory.
     *
     * @return string[]
     *   A map of environment name to the git branch it deploys from. Empty if
     *   the file is missing or does not declare a resolvable mapping.
     */
    protected function resolveDeploymentEnvironments(string $workflow_file): array
    {
        $path = "$this->applicationRoot/.github/workflows/$workflow_file";
        if (!is_file($path)) {
            return [];
        }

        $parsed = Yaml::parseFile($path);
        foreach ($parsed['jobs'] ?? [] as $job) {
            $with = $job['with'] ?? [];
            if (empty($with['branch-env-map']) || empty($with['deploy-branches'])) {
                continue;
            }

            $branch_to_env = json_decode($with['branch-env-map'], true) ?? [];
            $deploy_branches = json_decode($with['deploy-branches'], true) ?? [];
            $map = [];
            foreach ($deploy_branches as $branch) {
                // Fall back to an identity mapping if a deploy branch is absent
                // from the branch-env-map.
                $env = $branch_to_env[$branch] ?? $branch;
                $map[$env] = $branch;
            }
            return $map;
        }

        return [];
    }
}
