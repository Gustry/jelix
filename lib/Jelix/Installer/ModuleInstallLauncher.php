<?php
/**
* @author      Laurent Jouanneau
* @copyright   2008-2016 Laurent Jouanneau
* @link        http://www.jelix.org
* @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/
namespace Jelix\Installer;


use Jelix\Version\VersionComparator;
use \Jelix\Dependencies\Resolver;

/**
* a class to install a module.
* @since 1.2
*/
class ModuleInstallLauncher extends AbstractInstallLauncher {

    /**
     * @var jInstallerModule
     */
    protected $moduleInstaller = null;

    /**
     * @var jInstallerModule[]
     */
    protected $moduleUpgraders = null;

    /**
     * list of sessions Id of the component
     */
    protected $installerContexts = array();

    protected $upgradersContexts = array();

    /**
     * @param \Jelix\Core\Infos\ModuleInfos $moduleInfos
     * @param Installer $mainInstaller
     */
    function __construct(\Jelix\Core\Infos\ModuleInfos $moduleInfos,
                         Installer $mainInstaller = null) {
        parent::__construct($moduleInfos, $mainInstaller);
        if ($mainInstaller) {
            $ini = $mainInstaller->getInstallerIni();
            $contexts = $ini->getValue($moduleInfos->name.'.contexts','__modules_data');
            if ($contexts !== null && $contexts !== "") {
                $this->installerContexts = explode(',', $contexts);
            }
        }
    }

    protected function _setAccess(EntryPoint $ep)
    {
        $config = $ep->getConfigIni();
        $access = $config->getValue($this->moduleInfos->name . '.access', 'modules');

        $action = $this->getInstallAction($ep->getEpId());
        if ($action == Resolver::ACTION_INSTALL) {
            if ($access == 0 || $access == null) {
                $config->setValue($this->moduleInfos->name . '.access', 2, 'modules');
                $config->save();
            } else if ($access == 3) {
                $config->setValue($this->moduleInfos->name . '.access', 1, 'modules');
                $config->save();
            }
        }
        else if ($action == Resolver::ACTION_REMOVE) {
            $config->setValue($this->moduleInfos->name . '.access', 0, 'modules');
            $config->save();
        }
    }

    /**
     * get the object which is responsible to install the component. this
     * object should implement AbstractInstaller.
     *
     * @param EntryPoint $ep the entry point
     * @param boolean $installWholeApp true if the installation is done during app installation
     * @return ModuleInstaller the installer, or null if there isn't any installer
     *         or false if the installer is useless for the given parameter
     * @throws jInstallerException when install class not found
     */
    function getInstaller(EntryPoint $ep, $installWholeApp) {

        $this->_setAccess($ep);

        // false means that there isn't an installer for the module
        if ($this->moduleInstaller === false) {
            return null;
        }

        $epId = $ep->getEpId();

        if ($this->moduleInstaller === null) {
            if (!file_exists($this->moduleInfos->getPath().'install/install.php') ||
                             $this->moduleStatuses[$epId]->skipInstaller) {
                $this->moduleInstaller = false;
                return null;
            }
            require_once($this->moduleInfos->getPath().'install/install.php');
            $cname = $this->moduleInfos->name.'ModuleInstaller';
            if (!class_exists($cname))
                throw new Exception("module.installer.class.not.found",array($cname,$this->name));
            $this->moduleInstaller = new $cname($this->moduleInfos->name,
                                                $this->moduleInfos->name,
                                                $this->moduleInfos->getPath(),
                                                $this->moduleInfos->version,
                                                $installWholeApp
                                                );
        }

        $this->moduleInstaller->setParameters($this->moduleStatuses[$epId]->parameters);

        if ($ep->getLocalConfigIni()) {
            $sparam = $ep->getLocalConfigIni()->getValue($this->moduleInfos->name.'.installparam','modules');
        }
        else {
            $sparam = $ep->getConfigIni()->getValue($this->moduleInfos->name.'.installparam','modules');
        }

        if ($sparam === null)
            $sparam = '';
        $sp = $this->moduleStatuses[$epId]->serializeParameters();
        if ($sparam != $sp) {
            $ep->getConfigIni()->setValue($this->moduleInfos->name.'.installparam', $sp, 'modules');
        }

        $this->moduleInstaller->setEntryPoint($ep,
                                              $this->moduleStatuses[$epId]->dbProfile,
                                              $this->installerContexts);

        return $this->moduleInstaller;
    }

    /**
     * return the list of objects which are responsible to upgrade the component
     * from the current installed version of the component.
     *
     * this method should be called after verifying and resolving
     * dependencies. Needed components (modules or plugins) should be
     * installed/upgraded before calling this method
     *
     * @param EntryPoint $ep the entry point
     * @throw \Jelix\Installer\Exception  if an error occurs during the install.
     * @return ModuleInstaller[]
     */
    function getUpgraders(EntryPoint $ep) {

        $epId = $ep->getEpId();

        if ($this->moduleUpgraders === null) {

            $this->moduleUpgraders = array();

            $p = $this->moduleInfos->getPath().'install/';
            if (!file_exists($p)  || $this->moduleStatuses[$epId]->skipInstaller)
                return array();

            // we get the list of files for the upgrade
            $fileList = array();
            if ($handle = opendir($p)) {
                while (false !== ($f = readdir($handle))) {
                    if (!is_dir($p.$f)) {
                        if (preg_match('/^upgrade_to_([^_]+)_([^\.]+)\.php$/', $f, $m)) {
                            $fileList[] = array($f, $m[1], $m[2]);
                        }
                        else if (preg_match('/^upgrade_([^\.]+)\.php$/', $f, $m)){
                            $fileList[] = array($f, '', $m[1]);
                        }
                    }
                }
                closedir($handle);
            }

            if (!count($fileList)) {
                return array();
            }

            // now we order the list of file
            foreach($fileList as $fileInfo) {
                require_once($p.$fileInfo[0]);
                $cname = $this->moduleInfos->name.'ModuleUpgrader_'.$fileInfo[2];
                if (!class_exists($cname))
                    throw new Exception("module.upgrader.class.not.found",array($cname,$this->name));

                $upgrader = new $cname($this->moduleInfos->name,
                                        $fileInfo[2],
                                        $this->moduleInfos->getPath(),
                                        $fileInfo[1],
                                        false);

                if ($fileInfo[1] && count($upgrader->targetVersions) == 0) {
                    $upgrader->targetVersions = array($fileInfo[1]);
                }
                $this->moduleUpgraders[] = $upgrader;
            }
        }

        $list = array();
        foreach($this->moduleUpgraders as $upgrader) {

            $foundVersion = '';
            // check the version
            foreach($upgrader->targetVersions as $version) {

                if (VersionComparator::compareVersion($this->moduleStatuses[$epId]->version, $version) >= 0 ) {
                    // we don't execute upgraders having a version lower than the installed version (they are old upgrader)
                    continue;
                }
                if (VersionComparator::compareVersion($this->moduleInfos->version, $version) < 0 ) {
                    // we don't execute upgraders having a version higher than the version indicated in the module.xml/jelix-module.json
                    continue;
                }
                $foundVersion = $version;
                // when multiple version are specified, we take the first one which is ok
                break;
            }
            if (!$foundVersion)
                continue;

            $upgrader->version = $foundVersion;

            // we have to check now the date of versions
            // we should not execute the updater in some case.
            // for example, we have an updater for the 1.2 and 2.3 version
            // we have the 1.4 installed, and want to upgrade to the 2.5 version
            // we should not execute the update for 2.3 since modifications have already been
            // made into the 1.4. The only way to now that, is to compare date of versions
            if ($upgrader->date != '' && $this->mainInstaller) {
                $upgraderDate = $this->_formatDate($upgrader->date);
                $installerIni = $this->mainInstaller->getInstallerIni();

                // the date of the first version installed into the application
                $dt = $installerIni->getValue($this->moduleInfos->name.'.firstversion.date', $epId);
                $firstVersionDate = $this->_formatDate($dt);
                if ($firstVersionDate !== null) {
                    if ($firstVersionDate >= $upgraderDate)
                        continue;
                }

                // the date of the current installed version
                $dt = $installerIni->getValue($this->moduleInfos->name.'.version.date', $epId);
                $currentVersionDate = $this->_formatDate($dt);
                if ($currentVersionDate !== null) {
                    if ($currentVersionDate >= $upgraderDate)
                        continue;
                }
            }

            $upgrader->setParameters($this->moduleStatuses[$epId]->parameters);
            $class = get_class($upgrader);

            if (!isset($this->upgradersContexts[$class])) {
                $this->upgradersContexts[$class] = array();
            }

            $upgrader->setEntryPoint($ep,
                                    $this->moduleStatuses[$epId]->dbProfile,
                                    $this->upgradersContexts[$class]);
            $list[] = $upgrader;
        }
        // now let's sort upgrader, to execute them in the right order (oldest before newest)
        usort($list, function ($upgA, $upgB) {
                return VersionComparator::compareVersion($upgA->version, $upgB->version);
        });
        return $list;
    }

    public function installFinished($ep) {
        $this->installerContexts = $this->moduleInstaller->getContexts();
        if ($this->mainInstaller)
            $this->mainInstaller->getInstallerIni()->setValue($this->moduleInfos->name.'.contexts', implode(',',$this->installerContexts), '__modules_data');
    }

    public function upgradeFinished($ep, InstallerInterface $upgrader) {
        $class = get_class($upgrader);
        $this->upgradersContexts[$class] = $upgrader->getContexts();
    }

    public function uninstallFinished($ep) {
        if ($this->mainInstaller)
            $this->mainInstaller->getInstallerIni()->removeValue($this->moduleInfos->name.'.contexts', '__modules_data');
    }

    protected function _formatDate($date) {
        if ($date !== null) {
            if (strlen($date) == 10)
                $date.=' 00:00';
            else if (strlen($date) > 16) {
                $date = substr($date, 0, 16);
            }
        }
        return $date;
    }

}
