<?php

namespace NCAR\TerminusMultidevBranch\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;

class MultidevBranchCommand extends TerminusCommand
{
  /**
   * Create multi-dev on Pantheon with given branch-name, based on site-name.dev
   *
   * @command upstream:multidev-from-branch
   * @param string $site_name Pantheon site name
   * @param string $branch_name Git branch name
   */
  public function createMultidev($site_name, $branch_name)
  {
    $this->log()->notice("site name: {site_name}", ['site_name' => $site_name]);
  }
}