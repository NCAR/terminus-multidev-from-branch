<?php

namespace NCAR\TerminusMultidevBranch\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
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
  public function createMultidev($site_env, $branch_name)
  {
    /**
     * Make sure the site.env user wants to use as source exists
     * @var $site Site
     * @var $env Environment
     */
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    if(!$site || !$env)
    {
      $this->log()->error("Site {site-env} not found.", ['site-env' => $site_env]);
      return false;
    }

    // this is also the branch name on pantheon, and pantheon limits to 11 characters
    $multi_dev_name = strtolower(substr($branch_name, 0, 11));
    $branch = $multi_dev_name;

    $build_dir = "build-$branch_name";
    $multi_dev_site = $site->getName() . '.' . $multi_dev_name;

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
      $this->log()->notice("Creating {multidev-site}", ['multidev-site' => $multi_dev_site]);
    }

    $git_dir_options = "--git-dir=$build_dir/.git --work-tree=$build_dir/";

    //get the git clone command for the multidev

    //clone down the multidev locally

    //pull in the branch from github

    //set git mode

    //commit and push back up to pantheon

    return true;
  }
}