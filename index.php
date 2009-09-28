<?php

// Initialization & includes {{{1
error_reporting(E_ALL | E_STRICT);

ini_set('short_open_tag', '1');

set_include_path('include/');
require_once('core.class.php');
require_once('config.class.php');

setlocale(LC_ALL, Config::LOCALE);
date_default_timezone_set(Config::TIMEZONE);

require_once('glip/glip.php');
require_once('markup.class.php');
require_once('wikipage.class.php');
require_once('view.class.php');
require_once('mime.class.php');
require_once('cache.class.php');

// Functions {{{1
function redirect($uri) // {{{2
{
    header('Status: 303 See Other');
    header('Location: '.$uri);
}

function ls_r($path) // {{{2
{
    $dirs = array('');
    $r = array();

    if (!file_exists($path))
        return NULL;
    while (($dir = array_shift($dirs)) !== NULL)
    {
        $d = opendir($path.'/'.$dir);
        while (($entry = readdir($d)) !== FALSE)
        {
            if ($entry == '.' || $entry == '..')
                continue;
            $entry = $dir.$entry;
            if (is_dir($path.'/'.$entry))
                $dirs[] = $entry.'/';
            else
                $r[] = $entry;
        }
        closedir($d);
    }
    return $r;
}

function gentoken($len, $chrs='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789./-_') // {{{2
{
    $str = '';
    for ($i = 0; $i < $len; $i++)
        $str .= $chrs{mt_rand(0, strlen($chrs)-1)};
    return $str;
}

function edit_preview($content) // {{{2
{
    $view = new View('page-edit-preview.php');
    $view->contents = Markup::format($content);
    return $view->display(TRUE);
}
function search_normalize($str) // {{{2
{
    $str = strtolower($str);

    /* an effort to create better search results */
    $str = preg_replace('/[^a-z]+/', ' ', $str);

//    $str = preg_replace('/\s+/', ' ', $str);
    $str = trim($str);

    return $str;
}
// }}}1

$view = new View;
// Authentication {{{
$user = NULL;
if (Config::AUTHENTICATION)
{
    $pdo = new PDO(Config::DSN);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_COOKIE['session']))
    {
        $stmt = $pdo->prepare('SELECT * FROM ewiki_users WHERE "session" = :session');
        $stmt->execute(array('session' => $_COOKIE['session']));
        $user = $stmt->fetchObject();
        $stmt->closeCursor();

        if ($user === FALSE)
            $user = NULL;
    }
}

$view->user = $user;
// Git {{{1
$repo = new Git(Config::GIT_PATH);

$parts = explode('?', $_SERVER['REQUEST_URI'], 2);
assert(!strncmp($parts[0], Config::PATH, strlen(Config::PATH)));
$parts[0] = substr($parts[0], strlen(Config::PATH));

$tip = $repo->getTip(Config::GIT_BRANCH);
$commit = $tip;
if (isset($_GET['commit']))
    $commit = sha1_bin($_GET['commit']);
$commit = $repo->getObject($commit);
$commit_id = sha1_hex($commit->getName());
$view->commit_id = $commit_id;

$link_to_tip = !isset($_GET['commit']);
$commit_is_tip = ($commit->getName() == $tip);
$view->commit_is_tip = $commit_is_tip;

$view->n_conflicts = count(ls_r(sprintf('%s/refs/heads/%s', Config::GIT_PATH, Config::GIT_CONFLICT_BRANCH_DIR)));
// URL parsing {{{1
$special = $page = NULL;
if (!strncmp($parts[0], '/:', 2))
    $special = explode('/', substr($parts[0], 2), 2);
if ($special === NULL)
{
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action == '')
        $action = 'view';

    $page = WikiPage::fromURL($parts[0], $commit);
}
// }}}1

if ($special[0] == 'index')
{
    $action = 'view';
    $page = new WikiPage(array(), $commit);
    $special = NULL;
}

if ((Config::REQUIRE_LOGIN && !$user && $special[0] != 'rss20') || (Config::AUTHENTICATION && $special[0] == 'login')) // {{{1
{
    $view->setTemplate('login.php');

    $goto = $parts;
    if ($special && $special[0] == 'login')
        $goto[0] = '/'.$special[1];
    else if ($special)
        $goto[0] = '/:'.implode('/', $special);
    $goto = implode('?', $goto);
    $view->goto = $goto;
    $view->wrong = FALSE;
    if (isset($_POST['user']) && isset($_POST['password']))
    {
        $stmt = $pdo->prepare('SELECT * FROM ewiki_users WHERE "user" = :user AND "password" = :password');
        $stmt->execute(array('user' => $_POST['user'], 'password' => sha1($_POST['password'])));
        $user = $stmt->fetchObject();
        $stmt->closeCursor();

        if ($user)
        {
            $session = gentoken(10);
            $stmt = $pdo->prepare('UPDATE ewiki_users SET "session" = :session WHERE "user" = :user');
            $stmt->execute(array('user' => $user->user, 'session' => $session));
            $stmt->closeCursor();

            setcookie('session', $session, 0, Config::PATH . '/');
            redirect(Config::PATH . $goto);
            exit(0);
        }
        else
            $view->wrong = TRUE;
    }

    $view->display();
}
else if ($special[0] == 'recent' || $special[0] == "rss20") // {{{1
{
    if ($special[0] == "rss20")
        $view->setTemplate('rss20.php');
    else
        $view->setTemplate('recent-changes.php');

    $maxentries = 15;
    if (isset($special[1]))
        $maxentries = intval($special[1]);
    $maxentries = min(100, $maxentries);
    $view->maxentries = $maxentries;

    $commits = array();
    $history = array_reverse($commit->getHistory());
    for ($i = 0; $i < min($maxentries, count($history)); $i++)
    {
        $cur = $history[$i];

        $commits[$i] = new stdClass;
        $commits[$i]->commit_id = sha1_hex($cur->getName());
        $commits[$i]->author = $cur->author->name;
        $commits[$i]->email = $cur->author->email;
        $commits[$i]->time = $cur->committer->time;
        $commits[$i]->summary = $cur->summary;
        $commits[$i]->detail = $cur->detail;

        $changes = array();

        if (count($cur->parents) > 1)
        {
            $commits[$i]->merge = array_map('sha1_hex',  $cur->parents);
        }
        else
        {
            if (count($cur->parents))
                $prev = $repo->getObject($cur->parents[0]);
            else
                $prev = NULL;

            $commits[$i]->merge = array();
            foreach (GitCommit::treeDiff($prev, $cur) as $subject => $type)
            {
                $change = new stdClass;
                $change->subject = $subject;

                if ($type == GitTree::TREEDIFF_REMOVED)
                    $change->type = 'removed';
                else if ($type == GitTree::TREEDIFF_CHANGED)
                    $change->type = 'modified';
                else if ($type == GitTree::TREEDIFF_ADDED)
                    $change->type = 'added';

                $changes[] = $change;
            }
            foreach ($changes as $change)
            {
                $page = new WikiPage($change->subject);
                $change->subject_url = $page->getURL();
            }
        }
        $commits[$i]->changes = $changes;
    }
    $view->commits = $commits;

    $view->display();
}
else if ($special[0] == 'conflicts') // {{{1
{
    $view->setTemplate('conflicts.php');

    $conflict_branches = ls_r(sprintf('%s/refs/heads/%s', Config::GIT_PATH, Config::GIT_CONFLICT_BRANCH_DIR));
    if (!$conflict_branches)
        $conflict_branches = array();
    sort($conflict_branches);
    $conflicts = array();
    foreach ($conflict_branches as $name)
    {
        $obj = new stdClass;
        $obj->branch = sprintf('%s/%s', Config::GIT_CONFLICT_BRANCH_DIR, $name);
        $obj->merge_url = sprintf('%s/:merge/%s', Config::PATH, join('/', array_map('urlencode', explode('/', $obj->branch))));

        $conflicts[] = $obj;
    }

    $view->conflicts = $conflicts;

    $view->display();
}
else if ($special[0] == 'merge') // {{{1
{
    $view->setTemplate('merge.php');

    $A = new stdClass;
    $A->branch = Config::GIT_BRANCH;

    $B = new stdClass;
    $B->branch = $special[1];

    foreach (array($A, $B) as $I)
    {
        $I->tip = $repo->getObject($repo->getTip($I->branch));
        $I->commit_id = sha1_hex($I->tip->getName());
    }

    if (count($B->tip->parents) == 0)
        $parent = NULL;
    else if (count($B->tip->parents) == 1)
        $parent = $repo->getObject($B->tip->parents[0]);
    else
        throw new Exception('Not implemented: trying to merge a merge commit');

    $changes = GitCommit::treeDiff($parent, $B->tip);
    if (count($changes) == 0)
        throw new Exception('Not implemented: commit to be merged did not introduce any changes?!');
    else if (count($changes) != 1)
        throw new Exception('Not implemented: more than one file changed');

    list($path, $type) = each($changes);
    $view->page_name = $path;

    foreach (array($A, $B) as $I)
    {
        $I->page = new WikiPage($path, $I->tip);
        if ($I->page->getPageType() != WikiPage::TYPE_PAGE)
            throw new Exception('Not implemented: merging binary files, adding/removing files');
    }

    $view->A = $A;
    $view->B = $B;
    $view->fresh = isset($_GET['fresh']);

    $view->display();
}
else if ($special[0] == 'find') // {{{1
{
    $view->setTemplate('search-results.php');

    $query = NULL;
    if (!$query && isset($_POST['q']))
        $query = $_POST['q'];
    if (!$query && isset($_GET['q']))
        $query = $_GET['q'];
    if (!$query && isset($special[1]))
        $query = $special[1];
    $query = search_normalize($query);
    if (!$query)
        throw new Exception('no search term given');
    if (strlen($query) < 4)
        throw new Exception('please narrow your search');

    $view->query = $query;

    $blobs = $commit->getTree()->listRecursive();
    $results = array();
    foreach ($blobs as $path => $blob)
    {
        $data = $repo->getObject($blob)->data;
        $data = search_normalize($data);

        $result = new stdClass;
        $result->matches = array();

        for ($pos = 0; ($pos = strpos($data, $query, $pos)) !== FALSE; $pos++)
        {
            $match = new stdClass;
            $env = 20;
            $env_from = max(0, $pos - $env);
            $match->env = array(
                    ($env_from == 0 ? '' : '... ') . substr($data, $env_from, $pos - $env_from),
                    $query,
                    substr($data, $pos+strlen($query), $env) . ($pos+strlen($query)+$env >= strlen($data) ? '' : ' ...')
                );
            $result->matches[] = $match;
        }

        $hasmatch = count($result->matches) > 0;

        $path_s = search_normalize($path);
        if (($pos = strpos($path_s, $query)) !== FALSE)
            $hasmatch = TRUE;

        if ($hasmatch)
        {
            $result->page = new WikiPage($path);

            $results[] = $result;
        }

    }

    $view->results = $results;

    $view->display();
}
else if ($user && $special[0] == 'profile') // {{{1
{
    $view->setTemplate('edit-profile.php');

    $view->invalid_password = FALSE;
    if (isset($_POST['email']) && isset($_POST['newpass']))
    {
        $stmt = $pdo->prepare('UPDATE ewiki_users SET "email" = :email WHERE "user" = :user');
        $stmt->execute(array('user' => $user->user, 'email' => $_POST['email']));
        $stmt->closeCursor();
        $user->email = $_POST['email'];

        if ($_POST['newpass'])
        {
            if (strlen($_POST['newpass']) >= 3)
            {
                $stmt = $pdo->prepare('UPDATE ewiki_users SET "password" = :pass WHERE "user" = :user');
                $stmt->execute(array('user' => $user->user, 'pass' => sha1($_POST['newpass'])));
                $stmt->closeCursor();
            }
            else
                $view->invalid_password = TRUE;
        }

        if (!$view->invalid_password)
        {
            redirect(Config::PATH . '/');
            exit(0);
        }
    }

    $view->display();
}
else if ($special[0] == 'logout') // {{{1
{
    $stmt = $pdo->prepare('UPDATE ewiki_users SET "session" = NULL WHERE "user" = :user');
    $stmt->execute(array('user' => $user->user));
    $stmt->closeCursor();
    setcookie('session', '', 1, Config::PATH . '/');
    redirect(Config::PATH . '/');
}
else if ($special === NULL) // page-related {{{1
{
    $view->action = $action;
    $view->page = $page;
    if ($action == 'view') // {{{2
    {
        $view->setTemplate('page-view.php');

        $type = $page->getPageType();
        $view->type = $type;

        if ($type == WikiPage::TYPE_TREE)
        {
            /* FIXME: ignores current commit_id */
            $entries = $page->listEntries();
            usort($entries, create_function('$a, $b', 'return call_user_func(Config::TREE_VIEW_SORT, $a->getName(), $b->getName());'));
            $view->entries = $entries;
        }
        else if ($type == NULL)
        {
            $view->has_history = !!count($page->getPageHistory());
        }
        $view->display();
    }
    else if ($action == 'history') // {{{2
    {
        $view->setTemplate('page-history.php');

        $commits = $page->getPageHistory();
        $history = array();
        foreach ($commits as $commit)
        {
            $entry = new stdClass;
            $entry->summary = $commit->summary;
            $entry->author = $commit->author->name;
            $entry->time = $commit->committer->time;
            $entry->commit = sha1_hex($commit->getName());
            array_unshift($history, $entry);
        }
        $view->history = $history;

        $view->display();
    }
    else if ($action == 'edit') // {{{2
    {
        $committed = FALSE;
        if (isset($_POST['content']) && Config::ALLOW_EDIT)
        {
            if ($_POST['type'] == 'file')
                $content = file_get_contents($_FILES['file']['tmp_name']);
            else
                $content = str_replace("\r", '', str_replace("\r\n", "\n", $_POST['content']));

            if (!isset($_POST['preview']))
            {
                // Merge {{{
                /* first, create all new objects in memory */
                /* pending: contains all objects that need to be written */
                $pending = array();

                $blob = new GitBlob($repo);
                $pending[] = $blob;
                $blob->data = $content;
                $blob->rehash();

                $f = fopen(sprintf('%s/refs/heads/%s', $repo->dir, Config::GIT_BRANCH), 'a+b');
                flock($f, LOCK_EX);
                $ref = stream_get_contents($f);

                $fast_forward = FALSE;
                $fast_merge = FALSE;
                $commit_base = $commit;
                if (strlen($ref) == 0)
                {
                    /* create branch from scratch */
                    $fast_forward = TRUE;
                }
                else
                {
                    $ref = sha1_bin($ref);
                    if ($ref == $commit->getName())
                    {
                        /* no new commits */
                        $fast_forward = TRUE;
                    }
                    else
                    {
                        $tip = $repo->getObject($ref);
                        try
                        {
                            if ($tip->find($page->path) == $commit->find($page->path))
                            {
                                /*
                                 * New commits have been made, but the concerned file
                                 * has the same contents as when we started editing. We
                                 * directly perform the trivial merge.
                                 */
                                $fast_merge = TRUE;
                            }
                        }
                        catch (GitTreeError $e) {}
                    }
                }

                $tree = clone $repo->getObject($commit->tree);
                $pending = array_merge($pending, $tree->updateNode($page->path, 0100640, $blob->getName()));
                $tree->rehash();
                $pending[] = $tree;

                $newcommit = new GitCommit($repo);
                $newcommit->tree = $tree->getName();
                $newcommit->parents = array($commit->getName());
                $stamp = new GitCommitStamp;
                if ($user)
                {
                    $stamp->name = $user->name;
                    $stamp->email = $user->email;
                }
                else
                {
                    $stamp->name = Config::DEFAULT_USER_NAME;
                    $stamp->email = Config::DEFAULT_USER_EMAIL;
                }
                $stamp->time = time();
                $stamp->offset = idate('Z', $stamp->time);

                $newcommit->author = $stamp;
                $newcommit->committer = $stamp;

                $summary = $_POST['summary'];
                if (strpos($summary, $page->getName()) === FALSE)
                    $summary = sprintf('%s: %s', $page->getName(), $summary);
                $newcommit->summary = $summary;
                $newcommit->detail = '';
                $newcommit->rehash();
                $pending[] = $newcommit;

                if ($fast_merge)
                {
                    /* create merge commit */

                    $tree = clone $repo->getObject($tip->tree);
                    $pending = array_merge($pending, $tree->updateNode($page->path, 0100640, $blob->getName()));
                    $tree->rehash();
                    $pending[] = $tree;

                    $merge_base = $newcommit;

                    $newcommit = new GitCommit($repo);
                    $newcommit->tree = $tree->getName();
                    $newcommit->parents = array($tip->getName(), $merge_base->getName());
                    $newcommit->author = $stamp;
                    $newcommit->committer = $stamp;
                    $newcommit->summary = 'Fast merge';
                    $newcommit->detail = '';
                    $newcommit->rehash();
                    $pending[] = $newcommit;
                }

                if (!$fast_forward && !$fast_merge)
                {
                    fclose($f);

                    /* create conflict branch */

                    $dir = sprintf('%s/refs/heads/%s', $repo->dir, Config::GIT_CONFLICT_BRANCH_DIR);
                    if (!file_exists($dir))
                        mkdir($dir, 0755);
                    if (!is_dir($dir))
                        throw new Exception(sprintf('%s is not a directory', $dir));
                    if (!is_writable($dir))
                        throw new Exception(sprintf('cannot write to %s', $dir));

                    $f = FALSE;
                    for ($i = 1; !$f; $i++)
                    {
                        $branch = sprintf('%s/%02d', Config::GIT_CONFLICT_BRANCH_DIR, $i);
                        try
                        {
                            $f = fopen(sprintf('%s/refs/heads/%s', $repo->dir, $branch), 'xb');
                        }
                        catch (Exception $e)
                        {
                            /*
                             * fopen() will raise a warning if the file already
                             * exists, which Core will make into an Exception.
                             */
                        }
                    }
                    flock($f, LOCK_EX);
                }
                foreach ($pending as $obj)
                    $obj->write();
                ftruncate($f, 0);
                fwrite($f, sha1_hex($newcommit->getName()));
                fclose($f);

                if ($fast_forward || $fast_merge)
                    redirect($page->getURL());
                else
                    redirect(sprintf('%s/:merge/%s?fresh', Config::PATH, join('/', array_map('urlencode', explode('/', $branch)))));
                // }}}
                $committed = TRUE;
            }
        }

        if (!$committed)
        {
            $view->setTemplate('page-edit.php');
            $view->page_type = $page->getPageType();
            $view->is_binary = isset($content) ? $_POST['type'] == 'file' : ($view->page_type == WikiPage::TYPE_BINARY || $view->page_type == WikiPage::TYPE_IMAGE);
            if (isset($content) && !$view->is_binary)
            {
                $view->content = $content;
                if (isset($_POST['preview']))
                    $view->preview = edit_preview($content);
            }
            else
                $view->content = ($view->page_type == WikiPage::TYPE_PAGE ? $page->object->data : '');

            if (isset($content))
                $view->summary = $_POST['summary'];
            else
            {
                $view->summary = sprintf('%s: ', $page->getName());
                if (!$view->page_type)
                    $view->summary .= 'create page';
            }

            $view->display();
        }
    }
    else if ($action == 'get') // {{{2
    {
        header('Cache-Control: private, must-revalidate, no-cache');
        Cache::do_cache($page->getLastModified());

        header('Content-Type: '.$page->getMimeType());
        header('Content-Disposition: inline; filename="' . addcslashes($page->getName(), '"') . '"');
        header('Content-Length: '.strlen($page->object->data));
        echo $page->object->data;
    }
    else if ($action == 'image') // {{{2
    {
        header('Cache-Control: private, must-revalidate, no-cache');
        Cache::do_cache($page->getLastModified());

        assert($page->getPageType() == WikiPage::TYPE_IMAGE);
        header('Content-Type: '.$page->getMimeType());
        header('Content-Disposition: inline; filename="' . addcslashes($page->getName(), '"') . '"');

        if (isset($_GET['width']) || isset($_GET['height']))
        {
            // Resize (why on earth does php not have a simple image_resize function?)
            $old_image = imagecreatefromstring($page->object->data);
            $old_size = array(imagesx($old_image), imagesy($old_image));
            $new_size = array(isset($_GET['width'])  ? (int)$_GET['width']  : 0,
                              isset($_GET['height']) ? (int)$_GET['height'] : 0);
            if (!$new_size[0])
                $new_size[0] = $old_size[0];
            if (!$new_size[1])
                $new_size[1] = $old_size[1];
            $factor = min($new_size[0] / $old_size[0], $new_size[1] / $old_size[1]); // Keep aspect ratio
            if ($factor >= 1)
                imagedestroy($old_image);
        }
        else
            $factor = 1;

        if ($factor < 1)
        {
            $new_size = array($old_size[0] * $factor, $old_size[1] * $factor);
            $new_image = imagecreatetruecolor($new_size[0], $new_size[1]);
            imagealphablending($new_image, FALSE);
            imagecopyresampled($new_image, $old_image, 0, 0, 0, 0, $new_size[0], $new_size[1], $old_size[0], $old_size[1]);
            imagedestroy($old_image);

            // Send the image
            imagesavealpha($new_image, TRUE);
            switch ($page->getMimeType())
            {
                case 'image/gif':
                    imagegif($new_image);
                    break;
                case 'image/jpeg':
                    imagejpeg($new_image);
                    break;
                case 'image/png':
                    imagepng($new_image);
                    break;
                case 'image/vnd.wap.wbmp':
                    imagewbmp($new_image);
                    break;
                case 'image/x-xbitmap':
                    imagexbm($new_image);
                    break;
                default:
                    throw new Exception(sprintf('unhandled image type: %s', $page->getMimeType()));
            }
            imagedestroy($new_image);
        }
        else
            echo $page->object->data;

    }
    else // {{{2
        throw new Exception(sprintf('unhandled action: %s', $action));
    // }}}2
}
else if ($special !== NULL) // {{{1
    throw new Exception(sprintf('unknown special: %s', $special[0]));
// }}}1

/* vim:set fdm=marker fmr={{{,}}}: */

?>
