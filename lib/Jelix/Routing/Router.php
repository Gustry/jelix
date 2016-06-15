<?php
/**
* @author       Laurent Jouanneau
* @contributor  Thibault Piront (nuKs), Julien Issler, Dominique Papin, Flav, Gaëtan MARROT
* @copyright    2005-2015 laurent Jouanneau
* @copyright    2007 Thibault Piront
* @copyright    2008 Julien Issler
* @copyright    2008-2010 Dominique Papin, 2012 Flav, 2013 Gaëtan MARROT
* @link         http://www.jelix.org
* @licence      GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
*/
namespace Jelix\Routing;
use Jelix\Core\App;

/**
 * the main class of the routing core
 *
 * this is the "chief orchestra" of the framework. Its goal is
 * to load the configuration, to get the request parameters
 * used to instancie the correspondant controllers and to run the right method.
 */
class Router {

    /**
     * plugin list
     * @var  array
     */
    public $plugins = array();

    /**
     * current response object
     * @var \Jelix\Routing\ServerResponse
     */
    public $response = null;

    /**
     * current request object
     * @var \Jelix\Routing\ClientRequest
     */
    public $request = null;

    /**
     * the selector of the current action
     * @var jSelectorActFast
     */
    public $action = null;

    /**
     * the original action when there is an internal redirection to an action
     * different from the one corresponding to the request
     * @var jSelectorAct
     */
    public $originalAction = null;

    /**
     * the current module name
     * @var string
     */
    public $moduleName;

    /**
     * the current action name
     * @var string
     */
    public $actionName;

    /**
     * the current error message
     * @var \Jelix\Logger\Message\Error
     */
    protected $errorMessage = null;

    /**
     * @var \Jelix\Routing\UrlMapping\UrlActionMapper
     */
    protected $urlActionMapper = null;

    /**
     * @param  string|object $config filename of the ini file to configure the framework, or the config object itself
     *              this parameter is optional if App::loadConfig has been already called
     * @param  boolean $enableErrorHandler enable the error handler of jelix.
     *                 keep it to true, unless you have something to debug
     *                 and really have to use the default handler or an other handler
     */
    function __construct ($configFile='', $enableErrorHandler=true) {

        if ($configFile) {
            App::loadConfig($configFile, $enableErrorHandler);
        }

        $mapperConfig = new \Jelix\Routing\UrlMapping\MapperConfig(App::config()->urlengine);
        $this->urlActionMapper = new \Jelix\Routing\UrlMapping\UrlActionMapper($mapperConfig);
        $this->_loadPlugins();
    }

    function __clone() {
        $this->urlActionMapper = clone $this->urlActionMapper;
    }

    /**
     * load the plugins and their configuration file
     */
    private function _loadPlugins(){

        $config = App::config();
        foreach ($config->coordplugins as $name=>$conf) {
            if (strpos($name, '.') !== false)
                continue;
            // the config compiler has removed all deactivated plugins
            // so we don't have to check if the value $conf is empty or not
            if ($conf == '1') {
                $confname = 'coordplugin_'.$name;
                if (isset($config->$confname))
                    $conf = $config->$confname;
                else
                    $conf = array();
            }
            else {
                $conff = App::configPath($conf);
                if (false === ($conf = parse_ini_file($conff,true)))
                    throw new Exception("Error in a plugin configuration file -- plugin: $name  file: $conff", 13);
            }
            include_once($config->_pluginsPathList_coord[$name].$name.'.coord.php');
            $class= $name.'CoordPlugin';
            if (isset($config->coordplugins[$name.'.name']))
                $name = $config->coordplugins[$name.'.name'];
            $class = '\\'.$class;
            $this->plugins[strtolower($name)] = new $class($conf);
        }
    }

    public function getUrlActionMapper() {
        return $this->urlActionMapper;
    }

    public function setUrlActionMapper (\Jelix\Routing\UrlMapping\UrlActionMapper $urlActionMapper) {
        $this->urlActionMapper = $urlActionMapper;
    }

    /**
    * initialize the given request and some properties of the router
    *
    * It extracts information for the request to set the module name and the
    * action name. It doesn't verify if the corresponding controller does
    * exist or not.
    * It enables also the error handler of Jelix, if needed.
    * Does not call this method directly in entry points. Prefer to call
    * process() instead (that will call setRequest). 
    * setRequest is mostly used for tests or specific contexts.
    * @param  ClientRequest  $request the request object
    * @throw \jException if the module is unknown or the action name format is not valid
    * @see Router::process()
    */
    protected function setRequest (ClientRequest $request) {

        $config = App::config();
        $this->request = $request;

        if ($config->enableErrorHandler) {
            set_error_handler(array($this, 'errorHandler'));
            set_exception_handler(array($this, 'exceptionHandler'));

            // let's log messages appeared during init
            foreach(\jBasicErrorHandler::$initErrorMessages as $msg) {
                \Jelix\Logger\Log::log($msg, $msg->getCategory());
            }
        }

        $this->request->init($this->urlActionMapper);

        list($this->moduleName, $this->actionName) = $request->getModuleAction();
        App::pushCurrentModule($this->moduleName);

        $this->action =
        $this->originalAction = new \jSelectorActFast($this->request->type, $this->moduleName, $this->actionName);

        if ($config->modules[$this->moduleName.'.access'] < 2) {
            throw new \jException('jelix~errors.module.untrusted', $this->moduleName);
        }
    }

    /**
    * main method : launch the execution of the action.
    *
    * This method should be called in a entry point.
    *
    * @param  ClientRequest  $request the request object. It is required if a descendant of Router did not called setRequest before
    */
    public function process ($request=null) {

        try {
            if ($request) {
                $this->setRequest($request);
            }

            \jSession::start();

            $ctrl = $this->getController($this->action);
        }
        catch (\jException $e) {
            $notFoundAct = $this->urlActionMapper->getConfig()->notfoundAct;
            if ( $notFoundAct =='') {
                throw $e;
            }
            if (!\jSession::isStarted()) {
                \jSession::start();
            }
            try {
                $this->action = new \jSelectorAct($notFoundAct);
                $ctrl = $this->getController($this->action);
            }
            catch(\jException $e2) {
                throw $e;
            }
        }

        App::pushCurrentModule ($this->moduleName);

        if (count($this->plugins)) {
            $pluginparams = array();
            if(isset($ctrl->pluginParams['*'])){
                $pluginparams = $ctrl->pluginParams['*'];
            }

            if(isset($ctrl->pluginParams[$this->action->method])){
                $pluginparams = array_merge($pluginparams, $ctrl->pluginParams[$this->action->method]);
            }

            foreach ($this->plugins as $name => $obj){
                $result = $this->plugins[$name]->beforeAction ($pluginparams);
                if($result){
                    $this->action = $result;
                    App::popCurrentModule();
                    App::pushCurrentModule($result->module);
                    $this->moduleName = $result->module;
                    $this->actionName = $result->resource;
                    $ctrl = $this->getController($this->action);
                    break;
                }
            }
        }

        $this->response = $ctrl->{$this->action->method}();
        if($this->response == null){
            throw new \jException('jelix~errors.response.missing',$this->action->toString());
        }

        foreach ($this->plugins as $name => $obj){
            $this->plugins[$name]->beforeOutput ();
        }

        $this->response->output();

        foreach ($this->plugins as $name => $obj){
            $this->plugins[$name]->afterProcess ();
        }

        App::popCurrentModule();
        \jSession::end();
    }

    /**
     * get the controller corresponding to the selector
     * @param jSelectorActFast $selector
     */
    protected function getController(\jSelectorActFast $selector){

        $ctrlpath = $selector->getPath();
        if(!file_exists($ctrlpath)){
            throw new \jException('jelix~errors.ad.controller.file.unknown',array($this->actionName,$ctrlpath));
        }
        require_once($ctrlpath);
        $class = $selector->getClass();
        if(!class_exists($class,false)){
            throw new \jException('jelix~errors.ad.controller.class.unknown',array($this->actionName,$class, $ctrlpath));
        }
        $ctrl = new $class($this->request);
        if($ctrl instanceof \jIRestController){
            $method = $selector->method = strtolower($_SERVER['REQUEST_METHOD']);
        }elseif(!is_callable(array($ctrl, $selector->method))){
            throw new \jException('jelix~errors.ad.controller.method.unknown',array($this->actionName, $selector->method, $class, $ctrlpath));
        }
        return $ctrl;
    }

    /**
     * says if the currently executed action is the original one
     * @return boolean  true if yes
     */
    public function execOriginalAction() {
        if (!$this->originalAction) {
            return false;
        }
        return $this->originalAction->isEqualTo($this->action);
    }

    /**
     * Error handler using a response object to return the error.
     * Replace the default PHP error handler.
     * @param   integer     $errno      error code
     * @param   string      $errmsg     error message
     * @param   string      $filename   filename where the error appears
     * @param   integer     $linenum    line number where the error appears
     * @param   array       $errcontext
     * @since 1.4
     */
    function errorHandler($errno, $errmsg, $filename, $linenum, $errcontext) {

        if (error_reporting() == 0)
            return;

        if (preg_match('/^\s*\((\d+)\)(.+)$/', $errmsg, $m)) {
            $code = $m[1];
            $errmsg = $m[2];
        }
        else {
            $code = 1;
        }

        if (!isset (\jBasicErrorHandler::$errorCode[$errno])){
            $errno = E_ERROR;
        }
        $codestr = \jBasicErrorHandler::$errorCode[$errno];

        $trace = debug_backtrace();
        array_shift($trace);
        $this->handleError($codestr, $errno, $errmsg, $filename, $linenum, $trace);
    }

    /**
     * Exception handler using a response object to return the error
     * Replace the default PHP Exception handler
     * @param   Exception   $e  the exception object
     * @since 1.4
     */
    function exceptionHandler(\Exception $e) {
        $this->handleError('error', $e->getCode(), $e->getMessage(), $e->getFile(),
                          $e->getLine(), $e->getTrace());
    }

    /**
     * Handle an error event. Called by error handler and exception handler.
     * @param string  $type    error type : 'error', 'warning', 'notice'
     * @param integer $code    error code
     * @param string  $message error message
     * @param string  $file    the file name where the error appear
     * @param integer $line    the line number where the error appear
     * @param array   $trace   the stack trace
     * @since 1.1
     */
    public function handleError($type, $code, $message, $file, $line, $trace){

        $errorLog = new \Jelix\Logger\Message\Error($type, $code, $message, $file, $line, $trace);

        $errorLog->setFormat(App::config()->error_handling['messageLogFormat']);
        \Jelix\Logger\Log::log($errorLog, $type);

        // if non fatal error, it is finished, continue the execution of the action
        if ($type != 'error')
            return;

        $this->errorMessage = $errorLog;

        while (ob_get_level() && @ob_end_clean());

        $resp = $this->request->getErrorResponse($this->response);
        $resp->outputErrors();
        \jSession::end();

        exit(1);
    }

    /**
     * return the generic error message (errorMessage in the configuration).
     * Replaced the %code% pattern in the message by the current error code
     * @return string
     */
    public function getGenericErrorMessage() {
        $msg = App::config()->error_handling['errorMessage'];
        if ($this->errorMessage)
            $code = $this->errorMessage->getCode();
        else $code = '';
        return str_replace('%code%', $code, $msg);
    }

    /**
     * @return \Jelix\Logger\Message\Error the current error
     */
    public function getErrorMessage() {
        return $this->errorMessage;
    }

    /**
    * gets a given router plugin if registered
    * @param string   $pluginName   the name of the plugin
    * @param boolean  $required  says if the plugin is required or not. If true, will generate an exception if the plugin is not registered.
    * @return \Jelix\Routing\RouterPlugin
    */
    public function getPlugin ($pluginName, $required = true){
        $pluginName = strtolower ($pluginName);
        if (isset ($this->plugins[$pluginName])){
            $plugin = $this->plugins[$pluginName];
        }else{
            if ($required){
                throw new \jException('jelix~errors.plugin.unregister', $pluginName);
            }
            $plugin = null;
        }
        return $plugin;
    }

    /**
    * Says if the given router plugin $name is enabled
    * @param string $pluginName
    * @return boolean true : plugin is ok
    */
    public function isPluginEnabled ($pluginName){
        return isset ($this->plugins[strtolower ($pluginName)]);
    }
}
