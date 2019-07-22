<?php
/**
 * @author      Laurent Jouanneau
 * @copyright   2009-2018 Laurent Jouanneau
 *
 * @see        http://jelix.org
 * @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
 */

namespace Jelix\Installer;

use Jelix\IniFile\IniModifierInterface;

/**
 * container for module properties, according to a specific entry point configuration.
 *
 * It represents the state of the module, as known by the application:
 * installation status, the module version known during the last installer launch
 * etc.
 */
class ModuleStatus
{
    /**
     * @var string
     */
    public $name;

    /**
     * indicate if the module is enabled into the application or not.
     *
     * @var bool
     */
    public $isEnabled = false;
    /**
     * @var string
     */
    public $dbProfile;

    /**
     * indicate if the module is marked as installed.
     *
     * @var bool true/false or 0/1
     */
    public $isInstalled = false;

    /**
     * The version of the module that has been installed.
     *
     * @var string
     */
    public $version;

    /**
     * @var string[] parameters for installation
     */
    public $parameters = array();

    public $skipInstaller = false;

    /**
     * the module is configured for any instance.
     */
    const CONFIG_SCOPE_APP = 0;

    /**
     * the module is configured only at the instance level
     * (installed by the user, not by the developer).
     */
    const CONFIG_SCOPE_LOCAL = 1;

    /**
     * indicate if the module is configured into the app, or only for
     * the instance, so only into local configuration.
     *
     * @var int one of CONFIG_SCOPE_* constants
     */
    public $configurationScope = 0;

    protected $path;

    /**
     * @param string $name   the name of the module
     * @param string $path   the path to the module
     * @param array  $config configuration of modules ([modules] section),
     *                       generated by the configuration compiler for a specific
     *                       entry point
     */
    public function __construct($name, $path, $config)
    {
        $this->name = $name;
        $this->path = $path;
        $this->isEnabled = $config[$name.'.enabled'];
        $this->dbProfile = $config[$name.'.dbprofile'];
        $this->isInstalled = $config[$name.'.installed'];
        $this->version = $config[$name.'.version'];

        if (isset($config[$name.'.installparam'])) {
            $this->parameters = self::unserializeParameters($config[$name.'.installparam']);
        }

        if (isset($config[$name.'.skipinstaller']) && $config[$name.'.skipinstaller'] == 'skip') {
            $this->skipInstaller = true;
        }

        if (isset($config[$name.'.localconf'])) {
            $this->configurationScope = ($config[$name.'.localconf'] ? self::CONFIG_SCOPE_LOCAL : self::CONFIG_SCOPE_APP);
        }
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getName()
    {
        return $this->name;
    }

    public function saveInfos(IniModifierInterface $configIni, $defaultParameters = array())
    {
        $previous = $configIni->getValue($this->name.'.enabled', 'modules');
        if ($previous === null || $previous != $this->isEnabled) {
            $configIni->setValue($this->name.'.enabled', $this->isEnabled, 'modules');
        }

        $this->setConfigInfo($configIni, 'dbprofile', ($this->dbProfile != 'default' ? $this->dbProfile : ''), '');
        $this->setConfigInfo($configIni, 'installparam', self::serializeParametersAsArray($this->parameters, $defaultParameters), '');
        $this->setConfigInfo($configIni, 'skipinstaller', ($this->skipInstaller ? 'skip' : ''), '');
        $this->setConfigInfo(
            $configIni,
            'localconf',
            ($this->configurationScope == self::CONFIG_SCOPE_LOCAL ? self::CONFIG_SCOPE_LOCAL : 0),
            self::CONFIG_SCOPE_APP
        );
    }

    /**
     * @param IniModifierInterface $configIni
     * @param string               $name
     * @param mixed                $value
     * @param mixed                $defaultValue
     */
    private function setConfigInfo($configIni, $name, $value, $defaultValue)
    {
        // only modify the file when the value is not already set
        // to avoid to have to save the ini file  #perfs
        $previous = $configIni->getValue($this->name.'.'.$name, 'modules');
        if ($value !== $defaultValue) {
            if ($previous != $value) {
                $configIni->setValue($this->name.'.'.$name, $value, 'modules');
            }
        } elseif ($previous !== null) {
            // if the value is the default one, and there was a previous value
            // be sure to remove the key from the configuration file to
            // slim the configuration file
            $configIni->removeValue($this->name.'.'.$name, 'modules');
        }
    }

    public function clearInfos(IniModifierInterface $configIni)
    {
        foreach (array('enabled', 'dbprofile', 'installparam',
            'skipinstaller', 'localconf', ) as $param) {
            $configIni->removeValue($this->name.'.'.$param, 'modules');
        }
    }

    /**
     * Unserialize parameters coming from the ini file.
     *
     * Parameters could be fully serialized into a single string, or
     * could be as an associative array where only values are serialized
     * @param string|array $parameters
     * @return array
     */
    public static function unserializeParameters($parameters)
    {
        $trueParams = array();
        if (!is_array($parameters)) {
            $parameters = trim($parameters);
            if ($parameters == '') {
                return $trueParams;
            }
            $params = array();
            foreach (explode(';', $parameters) as $param) {
                $kp = explode('=', $param);
                if (count($kp)>1) {
                    $params[$kp[0]] = $kp[1];
                }
                else {
                    $params[$kp[0]] = true;
                }
            }
        }
        else {
            $params = $parameters;
        }

        foreach ($params as $key => $v) {
            if (is_string($v) && (strpos($v, ',') !== false || (strlen($v)  && $v[0] == '['))) {
                $trueParams[$key] = explode(',', trim($v,'[]'));
            } elseif ($v === 'false') {
                $trueParams[$key] = false;
            } elseif ($v === 'true') {
                $trueParams[$key] = true;
            } else {
                $trueParams[$key] = $v;
            }
        }

        return $trueParams;
    }

    /**
     * Serialize parameters to be stores into an ini file.
     *
     * The result is a single string with fully serialized array as found
     * in Jelix 1.6 or lower.
     *
     * @param array $parameters
     * @param array $defaultParameters
     * @return string
     */
    public static function serializeParametersAsString($parameters, $defaultParameters = array())
    {
        $p = array();
        foreach ($parameters as $name => $v) {
            if (is_array($v)) {
                if (!count($v)) {
                    continue;
                }
                $v = '['.implode(',', $v).']';
            }
            if (isset($defaultParameters[$name]) && $defaultParameters[$name] === $v && $v !== true) {
                // don't write values that equals to default ones except for
                // true values else we could not known into the installer if
                // the absence of the parameter means the default value or
                // it if means false
                continue;
            }
            if ($v === true || $v === 'true') {
                $p[] = $name;
            } elseif ($v === false || $v === 'false') {
                if (isset($defaultParameters[$name]) && is_bool($defaultParameters[$name])) {
                    continue;
                }
                $p[] = $name.'=false';
            } else {
                $p[] = $name.'='.$v;
            }
        }

        foreach($defaultParameters as $name => $v) {
            if ($v === true && !isset($parameters[$name])) {
                $p[] = $name;
            }
        }
        return implode(';', $p);
    }

    /**
     * Serialize parameters to be stores into an ini file.
     *
     * The result is an array with serialized value.
     *
     * @param array $parameters
     * @param array $defaultParameters
     * @return array
     */
    public static function serializeParametersAsArray($parameters, $defaultParameters = array())
    {
        $p = array();
        foreach ($parameters as $name => $v) {
            if (is_array($v)) {
                if (!count($v)) {
                    continue;
                }
                $v = '['.implode(',', $v).']';
            }
            if (isset($defaultParameters[$name]) && $defaultParameters[$name] === $v && $v !== true) {
                // don't write values that equals to default ones except for
                // true values else we could not known into the installer if
                // the absence of the parameter means the default value or
                // it if means false
                continue;
            }
            if ($v === true) {
                $p[$name] = true;
            } elseif ($v === false) {
                if (isset($defaultParameters[$name]) && is_bool($defaultParameters[$name])) {
                    continue;
                }
                $p[$name] = false;
            } else {
                $p[$name] = $v;
            }
        }

        foreach($defaultParameters as $name => $v) {
            if ($v === true && !isset($parameters[$name])) {
                $p[$name] = true;
            }
        }
        return $p;
    }
}
