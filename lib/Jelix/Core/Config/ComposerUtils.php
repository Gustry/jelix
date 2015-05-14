<?php
/**
* @author       Laurent Jouanneau
* @contributor  
* @copyright    2014 Laurent Jouanneau
* @link         http://www.jelix.org
* @licence      GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/

namespace Jelix\Core\Config;
use Jelix\Core\App as App;

class ComposerUtils {

    static function getAutoloader() {
        foreach(spl_autoload_functions() as $func) {
            if (is_array($func) && is_object($func[0])) {
                if (is_a($func[0], "\\Composer\\Autoload\\ClassLoader")) {
                    return $func[0];
                }
            }
        }
        return null;
    }


    static protected $packages = null;
    static function getInstalledPackages() {
        if (self::$packages === null) {
            self::$packages = array();
            $path = App::appPath('composer.lock');
            if (file_exists($path)) {
                $lock = json_decode(file_get_contents($path));
                if ($lock) {
                    foreach($lock->packages as $package) {
                        self::$packages[$package->name] = $package;
                    }
                }
            }
        }
        return self::$packages;
    }

    /**
     * Says if the given package has been loaded by Composer.
     * @param string $packageName  
     * @return boolean true if the given package has been loaded
     */
    static function isLoaded($packageName) {
        $packages = self::getInstalledPackages();
        return (isset($packages[$packageName]));
    }
}
