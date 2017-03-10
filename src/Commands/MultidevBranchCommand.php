<?php

namespace NCAR\TerminusMultidevBranch\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\Workflow;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

class MultidevBranchCommand extends TerminusCommand implements SiteAwareInterface
{
  use SiteAwareTrait;

  /**
   * Create a multi-dev from site-name.env, and merge in code from given branch-name.
   *
   * @command upstream:multidev-from-branch
   *
   * @param string $site_env Pantheon site-name.env
   * @param string $branch_name Git branch name
   *
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  public function createMultidevFromBranch($site_env, $branch_name)
  {
    /**
     * Make sure the site.env the user wants to use as source exists
     * @var $site Site
     * @var $env Environment
     */
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    if(!$site || !$env)
    {
      throw new TerminusException("Site {site-env} not found.", ['site-env' => $site_env]);
    }

    $site_name = strtok($site_env, '.');

    // this is also the branch name on pantheon, and pantheon limits to 11 characters
    $multi_dev_name = strtolower(substr($branch_name, 0, 11));
    $multi_dev_site = $site->getName() . '.' . $multi_dev_name;

    $build_dir = sys_get_temp_dir() . "/build-{$branch_name}";
    $clone_dir = $build_dir . '/' . $site->getName();

    if(!file_exists($build_dir))
    {
      if(!mkdir($build_dir))
      {
        throw new TerminusException("Failed to create $build_dir");
      }
    }

    //make sure the build/clone dir is clean
    shell_exec("rm -rf $clone_dir");

    $environments = $site->getEnvironments();

    //always start fresh by deleting the multidev if it already exists
    if($environments->has($multi_dev_name))
    {
      $env = $environments->get($multi_dev_name);
      $workflow = $env->delete(['delete_branch' => true]);
      while(!$workflow->checkProgress())
      {
        // @TODO: Add Symfony progress bar to indicate that something is happening.
      }
      if($workflow->isSuccessful())
      {
        $this->log()->notice('Deleted existing multidev environment {env}.', ['env' => $env->id,]);
      }
      else
      {
        throw new TerminusException($workflow->getMessage());
      }

      //repopulate info from api since terminus caches site/env info
      $site = $this->sites()->fetch()->get($site_name);
    }

    $this->log()->notice(
      "Creating {multi-dev-site} from {site-env}",
      ['multi-dev-site' => $multi_dev_site, 'site-env' => $site_env]
    );

    /** @var Workflow $workflow */
    $workflow = $site->getEnvironments()->create($multi_dev_name, $env);
    while (!$workflow->checkProgress())
    {
      // @TODO: Add Symfony progress bar to indicate that something is happening.
    }
    if(!$workflow->isSuccessful())
    {
      throw new TerminusException("Failed to create {multi-dev-site}", ['multi-dev-site' => $multi_dev_site]);
    }
    $this->log()->notice($workflow->getMessage());

    //repopulate again...
    $site = $this->sites()->fetch()->get($site_name);

    $git_dir_options = "--git-dir=$clone_dir/.git --work-tree=$clone_dir/";

    //get the git clone command for the multidev
    /** @var Environment $multi_dev_env */
    $multi_dev_env = $site->getEnvironments()->get($multi_dev_name);
    $info = $multi_dev_env->connectionInfo();

    if(!$info['git_command'])
    {
      throw new TerminusException("Git info not found for {multi-dev-site}", ['multi-dev-site' => $multi_dev_site]);
    }

    //clone down the multidev locally
    $this->log()->notice(
      "Cloning {multi-dev-site} to {clone-dir}",
      ['multi-dev-site' => $multi_dev_site, 'clone-dir' => $clone_dir]
    );
    $command = "pushd $build_dir && {$info['git_command']} && git $git_dir_options checkout -b $multi_dev_name && popd";
    $output = shell_exec($command);
    $this->log()->notice($output);

    //pull in the branch from github
    $this->log()->notice("Pulling from upstream...");
    $command = "git $git_dir_options pull git@github.com:NCAR/pantheon-umbrella-upstream.git $multi_dev_name --no-edit";
    $output = shell_exec($command);
    $this->log()->notice($output);

    //set git mode
    $workflow = $multi_dev_env->changeConnectionMode('git');
    if(is_string($workflow))
    {
      $this->log()->notice($workflow);
    }
    else
    {
      while (!$workflow->checkProgress())
      {
        // TODO: (ajbarry) Add workflow progress output
      }
      $this->log()->notice($workflow->getMessage());
    }

    //commit and push back up to pantheon
    $this->log()->notice("Pushing up to Pantheon...");
    $command = "git $git_dir_options push origin $multi_dev_name";
    $output = shell_exec($command);
    if($output)
    {
      $this->log()->notice($output);
    }
    else
    {
      $this->log()->notice(
        "Merged {multi-dev-name} to {multi-dev-site}",
        ['multi-dev-name' => $multi_dev_name, 'multi-dev-site' => $multi_dev_site]
      );
    }

    //clean up
    if(file_exists($build_dir))
    {
      $this->log()->notice("Cleaning up...");
      shell_exec("rm -rf $build_dir");
    }

    $url = "http://{$multi_dev_name}-{$site_name}.pantheon.io";
    $this->log()->notice("Done. Multi-dev created at {url}", ['url' => $url]);

  }
}