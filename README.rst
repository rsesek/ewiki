================================
eWiki - A simple, git-based Wiki
================================

Introduction
============
eWiki ("English Wiki") is a small `Wiki <http://en.wikipedia.org/wiki/Wiki>`_
written in PHP. Instead of using a database to store changes it uses `Git
<http://en.wikipedia.org/wiki/Git_(software)>`_. This allows you to run all
those fancy SCM operations like `bisect`, `blame` and `rebase` on your Wiki, as
well as editing it offline.

Requirements
============
* PHP >= 5.3.0, PECL fileinfo >= 0.1.0
* The `libmagic library <http://www.darwinsys.com/file/>`_ (or, `as a package <http://packages.debian.org/unstable/libdevel/libmagic-dev>`_)
* A server with mod_rewrite or another URL-rewriting mechanism
* A database with a `PDO driver <http://php.net/manual/en/pdo.drivers.php>`_ if you want user authentication

Installing eWiki
================

Installing dependencies
-----------------------
eWiki uses `glip <http://fimml.at/glip>`_ to access git repositories.
You should copy or symlink the `lib` folder of glip to `include/glip`.

Setting up a bare git repository
--------------------------------
This only sets up a bare repository that will be used by eWiki. You still need
another repository and at least one commit. Create an empty directory, cd into
it and type the following command on your workstation or directly on the
server, if you have shell access and git is installed::

    git init --bare
    git fetch /path/to/wiki/repository/ master:master

If you created the repository on your workstation, upload the resulting
directory structure to a path on your webserver.

Configuring eWiki
-----------------
Simply edit the file `include/config.class.php` according to the instructions
given in the file.

