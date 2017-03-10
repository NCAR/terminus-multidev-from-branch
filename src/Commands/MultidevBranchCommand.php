<?php

namespace NCAR\TerminusMultidevBranch\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\Workflow;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

class MultidevBranchCommand extends TerminusCommand implements SiteAwareInterface
{
  use SiteAwareTrait;

  /**
   * Create a multi-dev from site-name.dev, and merge in code from given branch-name.
   *
   * @command upstream:multidev-from-branch
   * @param string $site_env Pantheon site-name.env
   * @param string $branch_name Git branch name
   * @return bool
   */
  public function createMultidevFromBranch($site_env, $branch_name)
  {
    /**
     * Make sure the site.env the user wants to use as source exists
     * @var $site Site
     * @var $multi_dev_env$env Environment
     */
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    if(!$site || !$env)
    {
      $this->log()->error("Site {site-env} not found.", ['site-env' => $site_env]);
      return false;
    }

    // this is also the branch name on pantheon, and pantheon limits to 11 characters
    $multi_dev_name = strtolower(substr($branch_name, 0, 11));
    $multi_dev_site = $site->getName() . '.' . $multi_dev_name;

    $build_dir = sys_get_temp_dir() . "/build-$branch_name";
    $clone_dir = $build_dir . '/' . $site->getName();

    if(!file_exists($build_dir))
    {
      if(!mkdir($build_dir))
      {
        $this->log()->error("Failed to create $build_dir");
        return false;
      }
    }

    //make sure the build dir is clean

    //create the new multidev if it doesn't exist
    if(!$site->getEnvironments()->has($multi_dev_name))
    {
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
      $this->log()->notice($workflow->getMessage());
      if(!$workflow->isSuccessful())
      {
        $this->log()->error("Failed to create {multi-dev-site}", ['multi-dev-site' => $multi_dev_site]);
        return false;
      }
    }

    $git_dir_options = "--git-dir=$clone_dir/.git --work-tree=$clone_dir/";

    //get the git clone command for the multidev
    /** @var Environment $multi_dev_env */
    $multi_dev_env = $site->getEnvironments()->get($multi_dev_name);
    $info = $multi_dev_env->connectionInfo();

    if(!$info['git_command'])
    {
      $this->log()->error("Git info not found for {multi-dev-site}", ['multi-dev-site' => $multi_dev_site]);
      return false;
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
    $command = "git $git_dir_options pull git@github.com:NCAR/pantheon-umbrella-upstream.git $multi_dev_name";
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

    return true;
  }
}