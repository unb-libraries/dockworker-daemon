<?php

/**
 * Standalone regression test for the deployment env -> branch resolver.
 *
 * Drives Dockworker\Deployment\DeploymentWorkflowTrait::resolveDeploymentEnvironments()
 * against fixture workflow files. The resolver reads the application's GitHub
 * Actions workflow and inverts its 'branch-env-map' (keyed by branch, valued by
 * environment), restricted to 'deploy-branches', so:
 *
 *   - identity maps ({"dev":"dev","prod":"prod"}) round-trip unchanged;
 *   - non-identity maps ({"newacts":"prod"}) resolve env -> the real branch
 *     (this is the exact case, e.g. acts.lib.unb.ca, that a naive env==branch
 *     assumption would dispatch WRONGLY);
 *   - env keys whose branch is NOT in deploy-branches are excluded (build-only);
 *   - a missing file, or a workflow with no branch-env-map, resolves to [].
 *
 * Run:
 *   php vendor/unb-libraries/dockworker-daemon/test/deployment_workflow_resolver_test.php
 *   (or, from this repo: php test/deployment_workflow_resolver_test.php)
 */

declare(strict_types=1);

// Locate a Composer autoloader (needed for Symfony\Component\Yaml\Yaml),
// whether running from this repo or from a consuming application's vendor dir.
$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];
foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}
require_once __DIR__ . '/../src/Deployment/DeploymentWorkflowTrait.php';

/**
 * Minimal harness exposing the trait's resolver over a fixture directory.
 */
final class DeploymentWorkflowResolverTestHarness
{
    use Dockworker\Deployment\DeploymentWorkflowTrait;

    /**
     * The application root the trait reads workflow files beneath.
     *
     * @var string
     */
    public string $applicationRoot = '';

    /**
     * Resolves environments for a workflow file under a given root.
     *
     * @return string[]
     */
    public function resolve(string $root, string $file): array
    {
        $this->applicationRoot = $root;
        return $this->resolveDeploymentEnvironments($file);
    }
}

// -----------------------------------------------------------------------
// Fixtures: write workflow files into a temp .github/workflows directory.
// -----------------------------------------------------------------------
$root = sys_get_temp_dir() . '/dw_resolver_test_' . getmypid();
$workflow_dir = "$root/.github/workflows";
@mkdir($workflow_dir, 0777, true);

$make_workflow = static function (string $branch_env_map, string $deploy_branches): string {
    return <<<YAML
    name: Test
    on:
      workflow_dispatch:
    jobs:
      deploy:
        uses: unb-libraries/dockworker/.github/workflows/build-push-deploy-notify.yaml@6.x
        with:
          branch-env-map: '$branch_env_map'
          deploy-branches: '$deploy_branches'
    YAML;
};

file_put_contents(
    "$workflow_dir/identity.yaml",
    $make_workflow('{"dev":"dev","prod":"prod"}', '["dev","prod"]')
);
file_put_contents(
    "$workflow_dir/nonidentity.yaml",
    $make_workflow('{"newacts":"prod"}', '["newacts"]')
);
file_put_contents(
    "$workflow_dir/extrabranch.yaml",
    $make_workflow('{"dev":"dev","prod":"prod","17.x":"17.x"}', '["dev","prod"]')
);
// A custom workflow that does not use the reusable pipeline inputs.
file_put_contents(
    "$workflow_dir/custom.yaml",
    "name: Custom\non:\n  workflow_dispatch:\njobs:\n  build:\n    runs-on: ubuntu-latest\n"
);

// -----------------------------------------------------------------------
// Cases: [name, workflow file, expected map].
// -----------------------------------------------------------------------
$harness = new DeploymentWorkflowResolverTestHarness();
$pass = 0;
$fail = 0;

$assert = static function (string $name, string $file, array $expected) use ($harness, $root, &$pass, &$fail): void {
    $got = $harness->resolve($root, $file);
    ksort($got);
    ksort($expected);
    $ok = ($got === $expected);
    printf(
        "[%s] %-52s got=%s (expected %s)\n",
        $ok ? 'PASS' : 'FAIL',
        $name,
        json_encode($got),
        json_encode($expected)
    );
    $ok ? $pass++ : $fail++;
};

$assert('identity map', 'identity.yaml', ['dev' => 'dev', 'prod' => 'prod']);
$assert('non-identity (env prod -> branch newacts)', 'nonidentity.yaml', ['prod' => 'newacts']);
$assert('extra env key filtered by deploy-branches', 'extrabranch.yaml', ['dev' => 'dev', 'prod' => 'prod']);
$assert('custom workflow, no branch-env-map', 'custom.yaml', []);
$assert('missing workflow file', 'does-not-exist.yaml', []);

// Cleanup.
array_map('unlink', glob("$workflow_dir/*.yaml") ?: []);
@rmdir($workflow_dir);
@rmdir("$root/.github");
@rmdir($root);

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
