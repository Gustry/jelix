#!/bin/bash

ROOTDIR="/jelixapp"
MYSQL_VERSION="5.7"
PHP_VERSION="7.2"
APPNAME="testapp"
APPDIR="$ROOTDIR/$APPNAME"
VAGRANTDIR="$APPDIR/vagrant"
APPHOSTNAME="testapp18.local"
APPHOSTNAME2=""
LDAPCN="testapp18"
FPM_SOCK="php\\/php7.2-fpm.sock"
POSTGRESQL_VERSION=11

source $VAGRANTDIR/common_provision.sh

