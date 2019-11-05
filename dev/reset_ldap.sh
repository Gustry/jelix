#!/bin/bash
ROOTDIR="/jelixapp"
APPNAME="testapp"
APPDIR="$ROOTDIR/$APPNAME"
VAGRANTDIR="/vagrantscripts"
LDAPCN="testapp16"

ldapdelete -x -c -D cn=admin,dc=$LDAPCN,dc=local -w passjelix -f $VAGRANTDIR/ldap/ldap_delete.ldif
ldapadd -x -c -D cn=admin,dc=$LDAPCN,dc=local -w passjelix -f $VAGRANTDIR/ldap/ldap_conf.ldif
