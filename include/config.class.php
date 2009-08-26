<?php

final class Config
{
    // The name of the wiki
    const WIKI_NAME = 'eWiki';

    // The path of the wiki relative to the web root (no trailing slash)
    const PATH = '';

    // The template for the wiki (see templates/)
    const TEMPLATE = 'archaic';

    // The path to the git repository
    const GIT_PATH = '/srv/git/wiki.git';

    // The branch that should be used
    const GIT_BRANCH = 'master';

    /*
     * The sub-dir that conflict branches shall be created within.
     * e.g. 'conflict': conflict/01, conflict/02, ...
     */
    const GIT_CONFLICT_BRANCH_DIR = 'conflict';

    // Allow editing via web interface?
    const ALLOW_EDIT = true;

    // The locale used by the wiki
    const LOCALE = 'en_US.UTF-8';

    // The time zone of the wiki
    const TIMEZONE = 'GMT';

    // The default maximum image width
    const IMAGE_WIDTH = 640;

    // The default maximum image height
    const IMAGE_HEIGHT = 480;

    // Whether the wiki provides user management
    const AUTHENTICATION = false;

    // Whether the website requires authentication to view pages
    const REQUIRE_LOGIN = Config::AUTHENTICATION;

    /*
     * The DSN of the database to use.
     * Only used if Config::AUTHENTICATION is true
     */
    const DSN = 'pgsql:';

    // Treat empty files as non-existent
    const IGNORE_EMPTY_PAGES = false;

    // How to sort pages in tree view (comparison callback)
    const TREE_VIEW_SORT = 'strnatcmp';

    // Path to mime.cache
    const MIME_CACHE_PATH = '/usr/local/share/misc/magic.mgc';
}

?>
