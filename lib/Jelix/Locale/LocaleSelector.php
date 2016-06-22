<?php
/**
* see Jelix/Core/Selector/SelectorInterface.php for documentation about selectors.
* @author      Laurent Jouanneau
* @contributor Rahal
* @contributor Julien Issler
* @contributor Baptiste Toinot
* @copyright   2005-2014 Laurent Jouanneau
* @copyright   2007 Rahal
* @copyright   2008 Julien Issler
* @copyright   2008 Baptiste Toinot
* @link        http://www.jelix.org
* @licence    GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/
namespace Jelix\Locale;
use Jelix\Core\App;

/**
 * selector for localisation string
 *
 * localisation string are stored in file properties.
 * syntax : "module~prefixFile.keyString".
 * Corresponding file : locales/xx_XX/prefixFile.CCC.properties.
 * xx_XX and CCC are lang and charset set in the configuration
 */
class LocaleSelector extends \Jelix\Core\Selector\ModuleSelector {

    protected $type = 'loc';
    public $fileKey = '';
    public $messageKey = '';
    public $locale ='';
    public $charset='';
    protected $_where;

    function __construct($sel, $locale=null, $charset=null){

        if ($locale === null) {
            $locale = App::config()->locale;
        }
        if ($charset === null) {
            $charset = App::config()->charset;
        }
        if (strpos($locale,'_') === false) {
            $locale = Locale::langToLocale($locale);
        }
        $this->locale = $locale;
        $this->charset = $charset;
        $this->_suffix = '.'.$charset.'.properties';

        if ($this->_scan_sel($sel)) {
            if ($this->module =='') {
                $this->module = App::getCurrentModule ();
            }
            $this->_createPath();
            $this->_createCachePath();
        }
        else {
            throw new \Jelix\Core\Selector\Exception('jelix~errors.selector.invalid.syntax', array($sel,$this->type));
        }
    }

    protected function _scan_sel($selStr) {
        if (preg_match("/^(([a-zA-Z0-9_\.]+)~)?([a-zA-Z0-9_]+)\.([a-zA-Z0-9_\-\.]+)$/", $selStr, $m)) {
            if ($m[1]!='' && $m[2]!='') {
                $this->module = $m[2];
            }
            else {
                $this->module = '';
            }
            $this->resource = $m[3];
            $this->fileKey = $m[3];
            $this->messageKey = $m[4];
            return true;
        }
        return false;
    }

    protected function _createPath(){

        if (!isset(App::config()->_modulesPathList[$this->module])) {
            if ($this->module == 'jelix')
                throw new Exception('jelix module is not enabled !!');
            throw new \Jelix\Core\Selector\Exception('jelix~errors.selector.module.unknown', $this->toString());
        }

        $this->_cacheSuffix = '.'.$this->locale.'.'.$this->charset.'.php';

        // check if the locale has been overloaded in var/
        $overloadedPath = App::varPath('overloads/'.$this->module.'/locales/'.$this->locale.'/'.$this->resource.$this->_suffix);
        if (is_readable ($overloadedPath)){
            $this->_path = $overloadedPath;
            $this->_where = 'var/overloaded/';
            return;
        }

        // check if the locale is available in the locales directory in var/
        $localesPath = App::varPath('locales/'.$this->locale.'/'.$this->module.'/locales/'.$this->resource.$this->_suffix);
        if (is_readable ($localesPath)){
            $this->_path = $localesPath;
            $this->_where = 'var/locales/';
            return;
        }

        // check if the locale has been overloaded in app/
        $overloadedPath = jApp::appPath('app/overloads/'.$this->module.'/locales/'.$this->locale.'/'.$this->resource.$this->_suffix);
        if (is_readable ($overloadedPath)){
            $this->_path = $overloadedPath;
            $this->_where = 'app/overloaded/';
            return;
        }

        // check if the locale is available in the locales directory in app/
        $localesPath = jApp::appPath('app/locales/'.$this->locale.'/'.$this->module.'/locales/'.$this->resource.$this->_suffix);
        if (is_readable ($localesPath)){
            $this->_path = $localesPath;
            $this->_where = 'app/locales/';
            return;
        }

        // else check for the original locale file in the module
        $path = App::config()->_modulesPathList[$this->module].'locales/'.$this->locale.'/'.$this->resource.$this->_suffix;
        if (is_readable ($path)){
            $this->_where = 'modules/';
            $this->_path = $path;
            return;
        }

        // to avoid infinite loop in a specific lang or charset, we should check if we don't
        // try to retrieve the same message as the one we use for the exception below,
        // and if it is this message, it means that the error message doesn't exist
        // in the specific lang or charset, so we retrieve it in en_EN language and UTF-8 charset
        if($this->toString() == 'jelix~errors.selector.invalid.target'){
            $l = 'en_US';
            $c = 'UTF-8';
        }
        else{
            $l = null;
            $c = null;
        }
        throw new \Jelix\Core\Selector\Exception('jelix~errors.selector.invalid.target', array($this->toString(), "locale"), 1, $l, $c);
    }

    protected function _createCachePath(){
        // don't share the same cache for all the possible dirs
        // in case of overload removal
        $this->_cachePath = App::tempPath('compiled/locales/'.$this->_where.$this->module.'/'.$this->resource.$this->_cacheSuffix);
    }

    public function toString($full=false){
        if ($full)
            return $this->type.':'.$this->module.'~'.$this->fileKey.'.'.$this->messageKey;
        else
            return $this->module.'~'.$this->fileKey.'.'.$this->messageKey;
    }
}
