# terminus-multidev-from-branch
Terminus plugin to create a Multidev environment from a git branch, probably from a custom upstream.

## Usage
`terminus site:multidev-from-branch <site-name>.dev <branch-name> <git-url>`

where:
* \<site-name\> is the Pantheon site you want to use
* \<branch-name\> is the Git branch you want to use and Multidev you want to create
* \<git-url\> is the Git url to your custom upstream repo

When complete, you should have a new multidev at:
`http://<branch-name>-<site-name>.pantheon.io`
