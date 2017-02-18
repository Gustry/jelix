<?php
/**
 * @package     jelix
 * @subpackage  installer
 * @author      Laurent Jouanneau
 * @copyright   2009-2017 Laurent Jouanneau
 * @link        http://jelix.org
 * @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
 */
use Jelix\Routing\UrlMapping\XmlEntryPoint;

/**
 * container for entry points properties
 */
class jInstallerEntryPoint2 {

    /**
     * @var StdObj   configuration parameters. compiled content of config files
     *  result of the merge of entry point config, localconfig.ini.php,
     *  mainconfig.ini.php and defaultconfig.ini.php
     */
    protected $config;

    /**
     * @var string the filename of the configuration file dedicated to the entry point
     *       ex: <apppath>/app/config/index/config.ini.php
     */
    protected $configFile;


    /**
     * the mainconfig.ini.php file combined with defaultconfig.ini.php
     * @var \Jelix\IniFile\MultiIniModifier
     * @since 1.7.0
     */
    protected $mainConfigIni;

    /**
     * the localconfig.ini.php file combined with $mainConfigIni
     * @var \Jelix\IniFile\MultiIniModifier
     */
    protected $localConfigIni;

    /**
     * entrypoint config of app/config/
     * @var \Jelix\IniFile\IniModifier
     */
    protected $epConfigIni;

    /**
     * entrypoint config of var/config/
     * @var \Jelix\IniFile\IniModifier
     */
    protected $localEpConfigIni;

    /**
     * the entry point config combined with $localConfigIni
     * @var \Jelix\IniFile\MultiIniModifier
     */
    protected $fullConfigIni;

    /**
     * @var boolean true if the script corresponding to the configuration
     *                is a script for CLI
     */
    protected $_isCliScript;

    /**
     * @var string the url path of the entry point
     */
    protected $scriptName;

    /**
     * @var string the filename of the entry point
     */
    protected $file;

    /**
     * @var string the type of entry point
     */
    protected $type;

    /**
     * @var XmlEntryPoint
     */
    protected $urlMap;

    /**
     * @param \Jelix\IniFile\MultiIniModifier $mainConfig   the mainconfig.ini.php file combined with defaultconfig.ini.php
     * @param \Jelix\IniFile\MultiIniModifier $localConfig   the localconfig.ini.php file combined with $mainConfig
     * @param string $configFile the path of the configuration file, relative
     *                           to the app/config directory
     * @param string $file the filename of the entry point
     * @param string $type type of the entry point ('classic', 'cli', 'xmlrpc'....)
     */
    function __construct(\Jelix\IniFile\MultiIniModifier $mainConfig,
                         \Jelix\IniFile\MultiIniModifier $localConfig,
                         $configFile, $file, $type) {
        $this->type = $type;
        $this->_isCliScript = ($type == 'cmdline');
        $this->configFile = $configFile;
        $this->scriptName =  ($this->_isCliScript?$file:'/'.$file);
        $this->file = $file;
        $this->mainConfigIni = $mainConfig;
        $this->localConfigIni = $localConfig;

        $appConfigPath = jApp::appConfigPath($configFile);
        if (!file_exists($appConfigPath)) {
            jFile::createDir(dirname($appConfigPath));
            file_put_contents($appConfigPath, ';<'.'?php die(\'\');?'.'>');
        }
        $this->epConfigIni = new \Jelix\IniFile\IniModifier($appConfigPath);

        $varConfigPath = jApp::varConfigPath($configFile);
        if (!file_exists($varConfigPath)) {
            jFile::createDir(dirname($varConfigPath));
            file_put_contents($varConfigPath, ';<'.'?php die(\'\');?'.'>');
        }
        $this->localEpConfigIni = new \Jelix\IniFile\IniModifier($varConfigPath);


        $fullConfigIni = new \Jelix\IniFile\MultiIniModifier($localConfig, $this->epConfigIni);
        $this->fullConfigIni = new \Jelix\IniFile\MultiIniModifier($fullConfigIni, $this->localEpConfigIni);

        $this->config = jConfigCompiler::read($configFile, true,
                                              $this->_isCliScript,
                                              $this->scriptName);
    }

    protected $legacyInstallerEntryPoint = null;
    public function getLegacyInstallerEntryPoint() {
        if ($this->legacyInstallerEntryPoint === null) {
            $this->legacyInstallerEntryPoint = new jInstallerEntryPoint($this);
        }
        return $this->legacyInstallerEntryPoint;
    }

    public function getType() {
        return $this->type;
    }

    public function getScriptName() {
        return $this->scriptName;
    }

    public function getFileName() {
        return $this->file;
    }

    public function isCliScript() {
        return $this->_isCliScript;
    }

    public function setUrlMap(XmlEntryPoint $urlEp) {
        $this->urlMap = $urlEp;
    }

    public function getUrlMap() {
        return $this->urlMap;
    }

    /**
     * @return string the entry point id
     */
    function getEpId() {
        return $this->config->urlengine['urlScriptId'];
    }

    /**
     * @return array the list of modules and their path, as stored in the
     * compiled configuration file
     */
    function getModulesList() {
        return $this->config->_allModulesPathList;
    }

    /**
     * @return jInstallerModuleInfos informations about a specific module used
     * by the entry point
     */
    function getModule($moduleName) {
        return new jInstallerModuleInfos($moduleName, $this->config->modules);
    }

    /**
     * the mainconfig.ini.php file combined with defaultconfig.ini.php
     * @return \Jelix\IniFile\MultiIniModifier
     * @since 1.7
     */
    function getMainConfigIni() {
        return $this->mainConfigIni;
    }

    /**
     * the localconfig.ini.php file combined with $mainConfigIni
     * @return \Jelix\IniFile\MultiIniModifier
     * @since 1.7
     */
    function getLocalConfigIni() {
        return $this->localConfigIni;
    }

    /**
     * the entry point config (static and local) combined with $localConfigIni
     * @return \Jelix\IniFile\MultiIniModifier
     * @since 1.7
     */
    function getConfigIni() {
        return $this->fullConfigIni;
    }

    /*
     * the static entry point config alone (in app/config)
     * @return \Jelix\IniFile\IniModifier
     * @since 1.6.8
     */
    function getEpConfigIni() {
        return $this->epConfigIni;
    }

    /*
     * the local entry point config alone (in var/config)
     * @return \Jelix\IniFile\IniModifier
     * @since 1.7
     */
    function getLocalEpConfigIni() {
        return $this->localEpConfigIni;
    }

    /**
     * @return string the config file name of the entry point
     */
    function getConfigFile() {
        return $this->configFile;
    }

    /**
     * @return stdObj the config content of the entry point, as seen when
     * calling jApp::config()
     */
    function getConfigObj() {
        return $this->config;
    }

    function setConfigObj($config) {
        $this->config = $config;
    }
}
