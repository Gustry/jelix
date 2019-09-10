
Testapp is a web application used to test Jelix. It contains some pages where you can try
some features, and some unit tests.

A Vagrant configuration is given to execute testapp in an preconfigured environment with
all needed tools. You just have to install VirtualBox or Vagrant on your computer.

Sources of testapp
==================

It's better to clone the git repository from https://github.com/jelix/jelix.git to retrieve
testapp source files.


Testapp with Vagrant
====================

- install [Virtual box](https://www.virtualbox.org/) and [Vagrant](http://www.vagrantup.com/downloads.html)
- go into the testapp/vagrant/ directory, then in one of its sub-directory, 
  depending on which PHP version you want to launch tests : php7.0, php7.1, php7.2, php7.3 or php5.6.
  Then you can launch the vagrant virtual machine

```
cd testapp/vagrant/php7.3
vagrant up
```

It will create a virtual machine with all needed software for tests: mysql,
postgresql, redis, apache, php, phpunit, Composer etc.

It can take time the first time. It depends of your internet connection.

When the "Done" message appears, and if there are no errors, Testapp is
ready. Go on http://10.205.1.8/ to see the app and launch unit tests.

To shutdown the virtual machine, type

```
vagrant halt
```

You can also add in your hosts file a declaration of the testapp18.local domain

```
10.205.1.18  testapp18.local
```

And then use http://testapp18.local/ instead of http://10.205.1.8/


Running tests in Vagrant
------------------------

After installing Testapp, you can run tests on Jelix with Testapp.

- go into the right testapp/vagrant/phpX.Y directory and launch the vagrant virtual machine

```
  vagrant up
```

- go into this vm

```
  vagrant ssh
```

If you wrote new install or migration scripts, you can run the installer/updater

```
cd /jelixapp/testapp/install
php installer.php
```

Finally, to launch tests:

```
  cd /jelixapp/testapp/tests-jelix/
  phpunit
```

To reinstall testapp in Vagrant
-------------------------------

During development, it may appears that testapp is completely broken. You can reinstall
it without recreating the whole vm.

Follow these instructions:

```
# connection into the vm
vagrant ssh
# in the vm, go into the right directory and lanch the script which reset all things
cd /jelixapp/testapp/vagrant/
./reset_testapp.sh
```

Full Reinstall
--------------

You should destroy the vm. Example:

```
cd testapp/vagrant/php7.0
vagrant destroy
```

Then you can follow instruction to install testapp. See above.


Debugging Testapp with PHPStorm and Vagrant
===========================================

In PHPStorm, create a server: settings/Languages/PHP/servers

- Name : testapp 1.8
- Host: testapp18.local or 10.205.1.8 (serveur web)
- port: 80
- Debugger: xdebug
- Mapping : map the root of the repository to "/jelixapp"

Then create a debug configuration of type "PHP Remote Debug"
(menu Run/edit configurations)

- name: jelix 1.8
- servers : choose testapp 1.8
- ide key : PHPSTORM

Then you can debug:

- set a breakpoint in one of the PHP file of jelix
- launch the debugger in PHPStorm (the green "bug" button in the toolbar)
- load a page of testapp in your browser: the debugger should halt on your breakpoint
    

