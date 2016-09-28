<?php

/**
 * @package    pake
 * @author     Alexey Zakhlestin <indeyets@gmail.com>
 * @copyright  2009 Alexey Zakhlestin <indeyets@gmail.com>
 * @license    see the LICENSE file included in the distribution
 */

class pakeGit
{
    protected $_path;
    protected $_which           = null;
    protected $_allowedTypes    = array(
        'submodule',
        'remote'
    );
    
    const DEFAULT_SUBMODULE_BRANCH = 'master';

    /**
     * pakeGit constructor
     *
     * @param string        $path
     */
    public function __construct($path)
    {
        $this->init($path);
    }

    /**
     * Check if directory has is a root git directory
     *
     * @param string        $path
     * @return bool
     */
    public static function hasGitRespository($path)
    {
        return is_dir($path.'/.git');
    }

    /**
     * Initialise root git directory
     * 
     * @param string        $path
     * @return bool
     * @throws pakeException
     */
    public function init($path)
    {
        if (!self::hasGitRespository($path)) {
            $cwd = getcwd();
            $error = "'$path' does not existing in '$cwd'.";
            throw new pakeException($error);
        }
        $this->_path = realpath($path);
        return true;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->_path;
    }

    /**
     * @param array         $config
     * @throws pakeException
     */
    public function initProject($config)
    {
        if (empty($config)) {
            throw new pakeException("What's going on? I need config to initial a project :p.");
        }
//        $this->_validateProjectConfiguration($config);
        $this->_initProjectRecursive($config);
    }

    /**
     * @param array         $config
     */
    protected function _initProjectRecursive($config)
    {
        foreach ($config as $key => $value) {
            switch ($value['type']) {
                case 'submodule':
                    $repository     = $value['repository'];
                    $name           = $key;
                    $directory      = isset($value['directory']) ? $value['directory'] : null;
                    $branch         = isset($value['branch']) ? $value['branch'] : null;
                    $this->addSubmodule($repository, $name, $directory, $branch);
                    if (isset($value['remotes'])) {
                        $cwd = getcwd();
                        if ($directory) {
                            chdir($directory);
                        }
                        $this->_initProjectRecursive($value['remotes']);
                        chdir($cwd);
                    }
                    break;
                case 'remote':
                    $repository     = $value['repository'];
                    $name           = $key;
                    $directory      = isset($value['directory']) ? $value['directory'] : null;
                    $isNewRemote = $this->addRemote($repository, $name, $directory, $value['merge']);
                    if($isNewRemote === false) {
                        pake_echo_comment("Skipping adding remote " . $value['repository'] . "; Remote with this name already exists");
                    }
                    break;
            }

            if (isset($value['symlink'])) {
                if (!isset($value['symlink']['target'])) {
                    $error = "Symbolic link target not set.";
                    throw new pakeException($error);
                }
                if (!isset($value['symlink']['link'])) {
                    $error = "Symbolic link link not set.";
                    throw new pakeException($error);
                }
                if (!is_dir($value['symlink']['target'])) {
                    $error = "Symbolic link target directory '{$value['symlink']['target']}' doesn't exist.";
                    throw new pakeException($error);
                }
                $target     = $value['symlink']['target'];
                $link       = $value['symlink']['link'];
                $depth      = count(explode('/', $link)) - 1;
                $dirname    = pathinfo($link, PATHINFO_DIRNAME);
                $basename   = pathinfo($link, PATHINFO_BASENAME);

                if (!is_dir($link)) {
                    mkdir($dirname, 0777, true);
                }
                $cwd = getcwd();
                chdir($dirname);
                $target = str_repeat('../', $depth) . $target;

                symlink($target, $basename);
                chdir($cwd);
            }
        }

    }

    /**
     * @param array         $config
     * @throws pakeException
     */
    protected function _validateProjectConfiguration($config)
    {
        foreach ($config as $key => $value) {
            if (!isset($value['type'])) {
                throw new pakeException("You must defined 'type' for repositories.");
            }
            if (!in_array($value['type'], $this->_allowedTypes)) {
                throw new pakeException("'type' must be one of '" . implode(',', $this->_allowedTypes) . "'");
            }
            if (isset($value['remotes'])) {
                $this->_validateProjectConfiguration($value['remotes']);
            }
        }
    }

    /**
     * Add a submodule
     *
     * @param string        $repository
     * @param string        $name
     * @param string        $directory
     * @param string|null   $branch
     *
     * @return string|Exception
     */
    public function addSubmodule($repository, $name, $directory, $branch = null)
    {
        if (!$branch) {
            $branch = $this->_getHeadBranchFromRemote($repository);
        }
        if (!$directory) {
            throw new pakeException("You must supply a directory relative of the git root.");
        }
        if (is_dir($directory)) {
            return false;
        }
        return $this->_run('submodule add -f -b ' . $branch . ' --name ' . $name . ' ' . $repository . ' ' . $directory);
    }

    /**
     * @param string        $repository
     * @param string        $name
     * @param string|null   $directory
     * @param bool          $merge
     * @return string
     * @throws Exception
     */
    public function addRemote($repository, $name, $directory = null, $merge = false, $branch = 'master')
    {
        if (!$directory) {
            $directory = getcwd();
        } else {
            $directory = realpath($directory);
        }
        try {
            $result = $this->_run('remote add ' . $name . ' ' . $repository, $directory);

            if($merge) {
                $this->_run("fetch {$name}", $directory);
                $this->_run("merge {$name}/{$branch}", $directory);
            }
        } catch (pakeException $e) {
            if(preg_match("|remote {$name} already exists|", $e->getMessage()) === 1) {
                // Remote already exists
                $result = false;
            } else {
                throw $e;
            }
        }
        return $result;
    }

    /**
     * @param string        $remote
     * @return string
     * @throws pakeException
     */
    protected function _getHeadBranchFromRemote($remote)
    {
        $result = $this->_run('remote show ' . $remote);
        if (!$result) {
            $error = "There was now result for '.$remote.' check that the repository exists and is reachable.";
            throw new pakeException($error);
        }
        preg_match('/(?<=HEAD branch: ).[a-zA-Z0-9_-]*/i', $result, $matches);
        if (!empty($matches)) {
            return array_shift(array_values($matches));
        }
        $error = "There is no HEAD branch for '.$remote.'. Perhaps the repository hasn't been initialised yet.";
        throw new pakeException($error);
    }

    /**
     * Returns the correct binary path for git
     *
     * @return string
     * @throws pakeException
     */
    protected function _git()
    {
        if (is_null($this->_which)) {
            $this->_which = pake_which('git');
        }
        return $this->_which;
    }

    /**
     * Run the Git command
     *
     * @param string        $command
     * @param string|null|false $directory
     * @return string
     * @throws pakeException
     */
    protected function _run($command, $directory = null)
    {
        pake_echo_comment("Running $command in $directory");
        $cwd = getcwd();
        if (is_null($directory)) {
            $directory = $this->getPath();
        }
        if ($directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }
        try {
            if ($directory) {
                chdir($directory);
            }
            $command = $this->_git() . ' ' . $command;
            $result = pake_sh($command);
            if (getcwd() != $cwd) {
                chdir($cwd);
            }
        } catch (pakeException $e) {
            chdir($cwd);
            throw $e;
        }
        return $result;
    }

//    public function add($files = null)
//    {
//        if (null === $files) {
//            $files = array('--all');
//        } else {
//            $files = pakeFinder::get_files_from_argument($files, $this->repository_path, true);
//        }
//
//        $this->git_run('add '.implode(' ', array_map('escapeshellarg', $files)));
//
//        return $this;
//    }
//
//    public function commit($message = '', $all = false)
//    {
//        $this->git_run('commit -q -m '.escapeshellarg($message).($all ? ' -a' : ''));
//
//        return $this;
//    }
//
//    public function checkout($branch)
//    {
//        $this->git_run('checkout -q -f '.escapeshellarg($branch));
//
//        return $this;
//    }
//
//    public function pull($remote = null, $branch = null)
//    {
//        $cmd = 'pull -q';
//
//        if (null !== $remote) {
//            $cmd .= ' '.escapeshellarg($remote);
//
//            if (null !== $branch) {
//                $cmd .= ' '.escapeshellarg($branch);
//            }
//        }
//
//        $this->git_run($cmd);
//
//        return $this;
//    }
//
//    public function push($remote = null, $branch = null)
//    {
//        $cmd = 'push -q';
//
//        if (null !== $remote) {
//            $cmd .= ' '.escapeshellarg($remote);
//
//            if (null !== $branch) {
//                $cmd .= ' '.escapeshellarg($branch);
//            }
//        }
//
//        $this->git_run($cmd);
//
//        return $this;
//    }
//
//    public function logLast($number)
//    {
//        if (!is_numeric($number)) {
//            throw new pakeException('pakeGit::logLast() takes number, as parameter');
//        }
//
//        return $this->log('-'.$number);
//    }
//
//    public function logSince($commit_hash, $till = 'HEAD')
//    {
//        return $this->log($commit_hash.'..'.$till);
//    }
//
//    public function log($suffix)
//    {
//        $cmd = 'log --format="%H%x00%an%x00%ae%x00%at%x00%s"'.' '.$suffix;
//        $result = $this->git_run($cmd);
//
//        $data = array();
//        foreach (preg_split('/(\r\n|\n\r|\r|\n)/', $result) as $line) {
//            $line = trim($line);
//            if (strlen($line) == 0) {
//                continue;
//            }
//
//            $pieces = explode(chr(0), $line);
//
//            $data[] = array(
//                'hash' => $pieces[0],
//                'author' => array('name' => $pieces[1], 'email' => $pieces[2]),
//                'time' => new DateTime('@'.$pieces[3]),
//                'message' => $pieces[4]
//            );
//        }
//
//        return $data;
//    }
//
//    public function remotes()
//    {
//        $result = $this->git_run('remote -v');
//
//        $data = array();
//        foreach (preg_split('/(\r\n|\n\r|\r|\n)/', $result) as $line) {
//            $line = trim($line);
//            if (strlen($line) == 0) {
//                continue;
//            }
//
//            list($name, $tail) = explode("\t", $line, 2);
//
//            if (strpos($tail, '(fetch)') == strlen($tail) - 7) {
//                $data[$name]['fetch'] = substr($tail, 0, -7);
//            } elseif (strpos($tail, '(push)') == strlen($tail) - 6) {
//                $data[$name]['push'] = substr($tail, 0, -6);
//            }
//        }
//
//        return $data;
//    }
//
//
//    /**
//     * Run git-command in context of repository
//     *
//     * This method is useful for implementing some custom command, not implemented by pake.
//     * In cases when pake has native support for command, please use it, as it will provide better compatibility
//     *
//     * @param $command
//     */
//    public function git_run($command)
//    {
//        $git = escapeshellarg(pake_which('git'));
//
//        if (self::$needs_work_tree_workaround === true) {
//            $cmd = '(cd '.escapeshellarg($this->repository_path).' && '.$git.' '.$command.')';
//        } else {
//            $cmd = $git;
//            $cmd .= ' --git-dir='.escapeshellarg($this->repository_path.'/.git');
//            $cmd .= ' --work-tree='.escapeshellarg($this->repository_path);
//            $cmd .= ' '.$command;
//        }
//
//        try {
//            return pake_sh($cmd);
//        } catch (pakeException $e) {
//            if (strpos($e->getMessage(), 'cannot be used without a working tree') !== false ||
//                // workaround for windows (using win7 and git 1.7.10)
//                strpos($e->getMessage(), 'fatal: Could not switch to ') !== false) {
//                pake_echo_error('Your version of git is buggy. Using workaround');
//                self::$needs_work_tree_workaround = true;
//                return $this->git_run($command);
//            }
//
//            throw $e;
//        }
//    }
//
//    // new git-repo
//    public static function init($path, $template_path = null, $shared = false)
//    {
//        pake_mkdirs($path);
//
//        if (false === $shared)
//            $shared = 'false';
//        elseif (true === $shared)
//            $shared = 'true';
//        elseif (is_int($shared))
//            $shared = sprintf("%o", $shared);
//
//        $cmd = escapeshellarg(pake_which('git')).' init -q';
//
//        if (null !== $template_path) {
//            $cmd .= ' --template='.escapeshellarg($template_path);
//        }
//
//        $cmd .= ' --shared='.escapeshellarg($shared);
//
//        $cwd = getcwd();
//        chdir($path);
//        chdir('.'); // hack for windows. see http://docs.php.net/manual/en/function.chdir.php#88617
//        pake_sh($cmd);
//        chdir($cwd);
//
//        return new pakeGit($path);
//    }

//    public static function clone_repository($src_url, $target_path = null)
//    {
//        if (null === $target_path) {
//            // trying to "guess" path
//            $target_path = basename($src_url);
//
//            // removing suffix
//            if (substr($target_path, -4) === '.git')
//                $target_path = substr($target_path, 0, -4);
//        }
//
//        if (self::isRepository($target_path)) {
//            throw new pakeException('"'.$target_path.'" directory is a Git repository already');
//        }
//
//        if (file_exists($target_path)) {
//            throw new pakeException('"'.$target_path.'" directory already exists. Can not clone git-repository there');
//        }
//
//        pake_sh(escapeshellarg(pake_which('git')).' clone -q '.escapeshellarg($src_url).' '.escapeshellarg($target_path));
//
//        return new pakeGit($target_path);
//    }

//    // one-time operations
//    public static function add_to_repo($repository_path, $files = null)
//    {
//        $repo = new pakeGit($repository_path);
//        $repo->add($files);
//
//        return $repo;
//    }
//
//    public static function commit_repo($repository_path, $message = '', $all = false)
//    {
//        $repo = new pakeGit($repository_path);
//        $repo->commit($message, $all);
//
//        return $repo;
//    }
//
//    public static function checkout_repo($repository_path, $branch)
//    {
//        $repo = new pakeGit($repository_path);
//        $repo->checkout($branch);
//
//        return $repo;
//    }
//
//    public static function pull_repo($repository_path)
//    {
//        $repo = new pakeGit($repository_path);
//        $repo->pull();
//
//        return $repo;
//    }
}
