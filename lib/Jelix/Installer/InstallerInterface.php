<?php
/**
* @author      Laurent Jouanneau
* @copyright   2009-2014 Laurent Jouanneau
* @link        http://jelix.org
* @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/
namespace Jelix\Installer;


/**
* @since 1.2
*/
interface InstallerInterface {

    /**
     * Called before the installation of all other components
     * (dependents modules or the whole application).
     * Here, you should check if the component can be installed or not
     * @throws jException if an error occurs during the check of the installation
     */
    function preInstall();

    /**
     * should configure the component, install table into the database etc..
     * If an error occurs during the installation, you are responsible
     * to cancel/revert all things the method did before the error
     * @throws jException  if an error occurs during the install.
     */
    function install();

    /**
     * Redefine this method if you do some additionnal process after the installation of
     * all other modules (dependents modules or the whole application)
     * @throws jException  if an error occurs during the post installation.
     */
    function postInstall();

    /**
     * Called before the uninstallation of all other modules
     * (dependents modules or the whole application).
     * Here, you should check if the component can be uninstalled or not
     * @throws jException if an error occurs during the check of the installation
     */
    function preUninstall();

    /**
     * should configure the component, install table into the database etc.. 
     * @throws jException  if an error occurs during the install.
     */
    function uninstall();

    /**
     * 
     * @throws jException  if an error occurs during the install.
     */
    function postUninstall();

}

