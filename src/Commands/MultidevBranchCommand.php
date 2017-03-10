<?php

namespace NCAR\TerminusMultidevBranch\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\TerminusModel;
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
   * @param string $git_url Git url
   *
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  public function createMultidevFromBranch($site_env, $branch_name, $git_url)
  {
    /**
     * @var $site Site
     * @var $env Environment
     */
    list($site, $env) = $this->getSiteAndEnvironment($site_env);

    //set a bunch of variables, names, etc used
    list($site_name, ) = $this->parseSiteEnvId($site_env);

    // this is also the branch name on pantheon, and pantheon limits to 11 characters
    $multi_dev_name = strtolower(substr($branch_name, 0, 11));
    $multi_dev_site = $site->getName() . '.' . $multi_dev_name;

    $build_dir = sys_get_temp_dir() . "/build-{$branch_name}";
    $clone_dir = $build_dir . '/' . $site->getName();

    $git_dir_options = "--git-dir=$clone_dir/.git --work-tree=$clone_dir/";
    $git_remote_url = $this->normalizeGitUrl($git_url);

    $this->prepareDirectories($build_dir, $clone_dir);

    //always start fresh by deleting the multidev if it already exists
    $environments = $site->getEnvironments();
    if($environments->has($multi_dev_name))
    {
      $this->deleteEnvironment($environments->get($multi_dev_name));

      //repopulate info from api since terminus caches site/env info
      $site = $this->repopulateSiteInfo($site_name);
    }

    $this->log()->notice(
      "Creating {multi-dev-site} from {site-env}",
      ['multi-dev-site' => $multi_dev_site, 'site-env' => $site_env]
    );

    //create the multidev
    $this->createEnvironment($site, $multi_dev_name, $env);

    //repopulate site info again, now with new multidev goodness...
    $site = $this->repopulateSiteInfo($site_name);

    //get the git clone command for the new multidev
    /** @var Environment $multi_dev_env **/
    $multi_dev_env = $site->getEnvironments()->get($multi_dev_name);
    $git_command = $this->getGitCommand($multi_dev_env);

    //clone down the new multidev locally
    $this->shell(
      "pushd $build_dir && $git_command && git $git_dir_options checkout -b $multi_dev_name && popd",
      ["Cloning {multi-dev-site} to {clone-dir}", ['multi-dev-site' => $multi_dev_site, 'clone-dir' => $clone_dir]]
    );

    //pull in the branch from git remote
    $this->shell(
      "git $git_dir_options pull $git_remote_url $multi_dev_name --no-edit",
      ["Pulling from git remote..."]
    );

    //set mode to git
    $this->setConnectionMode($multi_dev_env, 'git');

    //push back up to pantheon
    $this->shell(
      "git $git_dir_options push origin $multi_dev_name",
      ["Pushing up to Pantheon..."]
    );

    //clean up
    $this->deleteDirectory($build_dir);

    $url = "http://{$multi_dev_name}-{$site_name}.pantheon.io";
    $this->log()->notice("Done. Multi-dev created at {url}", ['url' => $url]);
  }

  /**
   * Terminus, by default, only fetches data from API once and caches it, so if you change
   * something on the Pantheon server (e.g. create a multi-dev), you need to
   * refresh the data
   *
   * @param $site_name
   * @return \Pantheon\Terminus\Models\Site
   */
  protected function repopulateSiteInfo($site_name)
  {
    return $this->sites()->fetch()->get($site_name);
  }

  /**
   * Probably not necessary
   * @param $git_url string
   * @return string
   */
  protected function normalizeGitUrl($git_url)
  {
    return strtolower(substr($git_url, -4)) !== '.git' ? $git_url . '.git' : $git_url;
  }

  /**
   * Delete an environment on Pantheon
   *
   * @param $environment Environment|TerminusModel
   * @return bool
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function deleteEnvironment(Environment $environment)
  {
    $workflow = $environment->delete(['delete_branch' => TRUE]);
    while (!$workflow->checkProgress())
    {
      // @TODO: Add Symfony progress bar to indicate that something is happening.
    }
    if($workflow->isSuccessful())
    {
      $this->log()->notice('Deleted existing multidev environment {env}.', ['env' => $environment->id,]);
      return true;
    }
    else
    {
      throw new TerminusException($workflow->getMessage());
    }
  }

  /**
   * Create a new multidev with given name from environment
   *
   * @param $site Site
   * @param $multi_dev_name string Name of the new environment
   * @param $env Environment From which to create the new environment
   * @return \Pantheon\Terminus\Models\Workflow
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function createEnvironment($site, $multi_dev_name, $env)
  {
    /** @var Workflow $workflow */
    $workflow = $site->getEnvironments()->create($multi_dev_name, $env);
    while(!$workflow->checkProgress())
    {
      // @TODO: Add Symfony progress bar to indicate that something is happening.
    }
    if(!$workflow->isSuccessful())
    {
      throw new TerminusException("Failed to create {multi-dev-site}", ['multi-dev-site' => $site->getName() . '.' . $multi_dev_name]);
    }
    $this->log()->notice($workflow->getMessage());
    return $workflow;
  }

  /**
   * Build directory stuff
   *
   * @param $build_dir
   * @param $clone_dir
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function prepareDirectories($build_dir, $clone_dir)
  {
    if(!file_exists($build_dir))
    {
      if(!mkdir($build_dir))
      {
        throw new TerminusException("Failed to create $build_dir");
      }
    }
    else
    {
      //make sure the build/clone dir is clean
      $this->deleteDirectory($clone_dir);
    }
  }

  /**
   *
   * @param $site_env_id string site-name.env
   * @return array [site-name, env]
   */
  protected function parseSiteEnvId($site_env_id)
  {
    return array_map('trim', explode('.', $site_env_id));
  }

  /**
   * Set connection mode on an environment
   *
   * @param $multi_dev_env Environment
   * @param $mode string git|sftp
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function setConnectionMode(Environment $multi_dev_env, $mode)
  {
    if(!in_array(strtolower($mode), ['git', 'sftp']))
    {
      throw new TerminusException("Invalid connection mode: {mode}", ['mode' => $mode]);
    }
    $workflow = $multi_dev_env->changeConnectionMode($mode);
    if(is_string($workflow))
    {
      $this->log()->notice($workflow);
    }
    else
    {
      while(!$workflow->checkProgress())
      {
        // TODO: (ajbarry) Add workflow progress output
      }
      $this->log()->notice($workflow->getMessage());
    }
  }

  /**
   * rm a directory
   * @param $dir string /path/to/dir
   */
  protected function deleteDirectory($dir)
  {
    if(file_exists($dir))
    {
      $this->log()->notice("Removing $dir...");
      shell_exec("rm -rf $dir");
    }
  }

  /**
   * @param $environment Environment
   * @return mixed
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function getGitCommand(Environment $environment)
  {
    $info = $environment->gitConnectionInfo();

    if (!$info['command'])
    {
      throw new TerminusException("Git info not found for {multi-dev-site}", ['multi-dev-site' => $environment->getSite()->getName() . '.' . $environment->getName()]);
    }

    return $info['command'];
  }

  /**
   * @param $site_env string site-name.env
   * @return array [Site, Environment]
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   */
  protected function getSiteAndEnvironment($site_env)
  {
    list($site, $env) = $this->getOptionalSiteEnv($site_env);

    if (!$site || !$env)
    {
      throw new TerminusException("Site {site-env} not found.", ['site-env' => $site_env]);
    }

    return [$site, $env];
  }

  /**
   * @param $command string
   * @param array $info_message [message, [params]]
   * @return string
   */
  public function shell($command, array $info_message = ['', []])
  {
    if($info_message && isset($info_message[0]))
    {
      $this->log()->notice($info_message[0], (isset($info_message[1]) && !empty($info_message[1]) ? $info_message[1] : []));
    }

    $output = shell_exec($command);
    if($output) $this->log()->notice($output);

    return $output;
  }
}