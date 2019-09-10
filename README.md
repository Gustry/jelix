# Jelix

[![Build Status](https://travis-ci.org/jelix/jelix.svg?branch=master)](https://travis-ci.org/jelix/jelix)
[![License](https://poser.pugx.org/jelix/jelix/license)](https://packagist.org/packages/jelix/jelix)
[![Latest Stable Version](https://poser.pugx.org/jelix/jelix/v/stable)](https://packagist.org/packages/jelix/jelix)
[![Total Downloads](https://poser.pugx.org/jelix/jelix/downloads)](https://packagist.org/packages/jelix/jelix)
[![Latest Unstable Version](https://poser.pugx.org/jelix/jelix/v/unstable)](https://packagist.org/packages/jelix/jelix)

What is Jelix?
==============

Jelix 2 is an open-source framework for PHP 7.3+.

It has a modular and extensible architecture. Applications based on Jelix are made
with modules, which allow to reuse features in several projects.

For more informations, read [details about its features](https://jelix.org/articles/en/features).

About stable versions and branches
==================================

**WARNING**: many changes occurs in the master branch, because of a "namespacification"
which is not finished yet. So API may change or may be broken (even if we try to no do it)
**Don't use it for production for the moment**! However if you want to migrate an existing
application to this unstable Jelix version ( **just for tests**! ), read the file
[UPGRADE-TO-2.0.md].

The master branch will be Jelix 2.0.

For the current stable release, see the jelix-1.6.x branch. For the next stable release,
see the jelix-1.7.x branch.

**Please**, to fix issues on stable versions, do it on their corresponding branches,
not master! So **do pull requests** on stable branches!

Installation
===========

The source code of the master branch (Jelix 2.0) is compatible only with PHP 5.6 or more.

The best way is to use [Composer](https://getcomposer.org).
Create a directory and a composer.json file : 

```
{
    "name": "...",
    "require": {
        "php": ">=5.6",
        "jelix/jelix": "dev-master"
    }
}
```

Then you run:

```
composer install
```

Instead of using Composer, you can also download directly a package containing Jelix ready
to use. See [the download page](https://jelix.org/articles/en/download).

Read [the documentation to create an application](https://docs.jelix.org/en/manual-1.8/installation/create-application).

Documentation and community
========================

There is a full manual to learn Jelix. You can read it [direcly on the website](https://docs.jelix.org/en/manual-1.8).

You can ask your questions [on the forum](https://jelix.org/forums/forum/cat/2-english) or
on our IRC Channel, #jelix, on the irc.freenode.net network.

Contribution & development
===========================

see CONTRIBUTING.md.
