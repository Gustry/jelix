<?php
/**
 * @author      Laurent Jouanneau
 * @copyright   2008-2018 Laurent Jouanneau
 * @link        http://www.jelix.org
 * @licence     GNU Lesser General Public Licence see LICENCE file or http://www.gnu.org/licenses/lgpl.html
 */
namespace Jelix\Installer;

use \Jelix\Dependencies\Item;
use \Jelix\Dependencies\Resolver;
use \Jelix\Dependencies\ItemException;

use Jelix\Installer\Module\InteractiveConfigurator;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * main class to configure modules
 *
 * It loads all entry points configurations and all informations about activated
 * modules. Configurator then constructs a tree dependencies for these
 * activated modules, and launch configuration of given module
 *
 * @since 1.7
 */
class Configurator {

    /**
     * error code stored in a component: impossible to install
     * the module because dependencies are missing
     */
    const INSTALL_ERROR_MISSING_DEPENDENCIES = 1;

    /**
     * error code stored in a component: impossible to install
     * the module because of circular dependencies
     */
    const INSTALL_ERROR_CIRCULAR_DEPENDENCY = 2;

    /**
     * error code stored in a component:
     */
    const INSTALL_ERROR_CONFLICT = 3;

    /**
     * the main entrypoint of the application
     * @var EntryPoint
     */
    protected $mainEntryPoint = null;

    /**
     * the object responsible of the results output
     * @var Reporter\ReporterInterface
     */
    protected $reporter;

    /**
     * @var \JInstallerMessageProvider
     */
    protected $messages;

    /**
     * the global app setup
     * @var GlobalSetup
     */
    protected $globalSetup;

    /**
     * @var QuestionHelper
     */
    protected $questionHelper = null;

    /**
     * @var InputInterface
     */
    protected $consoleInput = null;

    /**
     * @var OutputInterface
     */
    protected $consoleOutput = null;

    protected $moduleParameters = array();

    /**
     * initialize the configuration
     *
     * GlobalSetup reads configurations files of all entry points, and prepare object for
     * each module, needed to configure modules.
     *
     * @param Reporter\ReporterInterface $reporter  object which is responsible to process messages (display, storage or other..)
     * @param string $lang  the language code for messages
     */
    public function __construct (Reporter\ReporterInterface $reporter, GlobalSetup $globalSetup, $lang='') {
        $this->reporter = $reporter;
        $this->messages = new \Jelix\Installer\Checker\Messages($lang);

        $this->globalSetup = $globalSetup;

        $this->mainEntryPoint = $globalSetup->getMainEntryPoint();
    }

    /**
     * set parameters for the installer of a module
     * @param string $moduleName the name of the module
     * @param array $parameters  parameters
     */
    public function setModuleParameters($moduleName, $parameters) {
        $this->moduleParameters[$moduleName] = $parameters;
    }


    public function setInteractiveMode(QuestionHelper $helper, InputInterface $input, OutputInterface $output) {
        $this->consoleInput = $input;
        $this->consoleOutput = $output;
        $this->questionHelper = $helper;
    }

    static public function setModuleAsConfigured($moduleName, \Jelix\IniFile\IniModifierInterface $configIni) {
        $configIni->setValue($moduleName.'.enabled', true , 'modules');
    }

    /**
     * @param array $modulesList array of module names
     * @param string $dedicatedEntryPointId entry point from which the module will
     *        be mainly accessible
     * @param bool|null $forLocalConfig true if the configuration should be done into
     *                         the local configuration instead of app configuration (false).
     *                          give null to use the default configuration mode
     * @param bool $forceReconfigure true if an already configured module should
     *              be reconfigured
     */
    public function configureModules($modulesList,
                                     $dedicatedEntryPointId = 'index',
                                     $forLocalConfig = null,
                                     $forceReconfigure = false
    ) {
        $this->startMessage();

        // check that all given modules are existing
        $hasError = false;
        foreach ($modulesList as $name) {
            $component = $this->globalSetup->getModuleComponent($name);
            if (!$component) {
                $this->error('module.unknown', $name);
                $hasError = true;
            }
        }
        if ($hasError) {
            return false;
        }
        // get all modules and their dependencies
        $resolver = new Resolver();
        foreach($this->globalSetup->getModuleComponentsList() as $name => $module) {
            $resolverItem = $module->getResolverItem();
            if (in_array($name, $modulesList)) {
                if (!$component->isEnabled() || $forceReconfigure) {
                    $resolverItem->setAction(Resolver::ACTION_INSTALL);
                }
            }
            $resolver->addItem($resolverItem);
        }

        // configure modules
        $modulesChain = $this->resolveDependencies($resolver);
        $modulesToConfigure = array();

        foreach ($modulesChain as $resolverItem) {
            if ($resolverItem->getAction() == Resolver::ACTION_INSTALL) {
                $modulesToConfigure[] = $resolverItem;
            }
        }

        $this->notice('configuration.start');
        $entryPoint = $this->globalSetup->getEntryPointById($dedicatedEntryPointId);
        \jApp::setConfig($entryPoint->getConfigObj());

        if ($entryPoint->getConfigObj()->disableInstallers) {
            $this->notice('install.installers.disabled');
        }

        $this->globalSetup->setCurrentConfiguratorStatus($forLocalConfig);

        $componentsToConfigure = $this->runPreConfigure($modulesToConfigure, $entryPoint, $forLocalConfig);
        if ($componentsToConfigure === false) {
            $this->warning('configuration.bad.end');
            return false;
        }

        if (!$this->runConfigure($componentsToConfigure, $entryPoint)) {
            $this->warning('configuration.bad.end');
            return false;
        }

        $result = $this->runPostConfigure($componentsToConfigure, $entryPoint);
        if (!$result) {
            $this->warning('configuration.bad.end');
        }
        else {
            $this->ok('configuration.end');
        }
        $this->globalSetup->getUninstallerIni()->save();

        $this->endMessage();
        return $result;
    }

    protected function resolveDependencies(Resolver $resolver) {

        try {
            $moduleschain = $resolver->getDependenciesChainForInstallation(true);
        }
        catch(ItemException $e) {
            $item = $e->getItem();
            $component = $item->getProperty('component');

            switch($e->getCode()) {
                case ItemException::ERROR_CIRCULAR_DEPENDENCY:
                case ItemException::ERROR_REVERSE_CIRCULAR_DEPENDENCY:
                    $component->inError = self::INSTALL_ERROR_CIRCULAR_DEPENDENCY;
                    $this->error('module.circular.dependency',$component->getName());
                    break;
                case ItemException::ERROR_BAD_ITEM_VERSION:
                    $depName = $e->getRelatedData()->getName();
                    $maxVersion = $minVersion = 0;
                    foreach($component->getDependencies() as $compInfo) {
                        if ($compInfo['type'] == 'module' && $compInfo['name'] == $depName) {
                            $maxVersion = $compInfo['maxversion'];
                            $minVersion = $compInfo['minversion'];
                        }
                    }
                    $this->error('module.bad.dependency.version',array($component->getName(), $depName, $minVersion, $maxVersion));
                    break;
                case ItemException::ERROR_REMOVED_ITEM_IS_NEEDED:
                    $depName = $e->getRelatedData()->getName();
                    $this->error('install.error.delete.dependency',array($depName, $component->getName()));
                    break;
                case ItemException::ERROR_ITEM_TO_INSTALL_SHOULD_BE_REMOVED:
                    $depName = $e->getRelatedData()->getName();
                    $this->error('install.error.install.dependency',array($depName, $component->getName()));
                    break;
                case ItemException::ERROR_DEPENDENCY_MISSING_ITEM:
                    $component->inError = self::INSTALL_ERROR_MISSING_DEPENDENCIES;
                    $this->error('module.needed', array($component->getName(), implode(',',$e->getRelatedData())));
                    break;
                case ItemException::ERROR_INSTALLED_ITEM_IN_CONFLICT:
                    $component->inError = self::INSTALL_ERROR_CONFLICT;
                    $this->error('module.forbidden', array($component->getName(), implode(',',$e->getRelatedData())));
                    break;
                case ItemException::ERROR_ITEM_TO_INSTALL_IN_CONFLICT:
                    $component->inError = self::INSTALL_ERROR_CONFLICT;
                    $this->error('module.forbidden', array($component->getName(), implode(',',$e->getRelatedData())));
                    break;
                case ItemException::ERROR_CHOICE_MISSING_ITEM:
                    $component->inError = self::INSTALL_ERROR_MISSING_DEPENDENCIES;
                    $this->error('module.choice.unknown', array($component->getName(), implode(',',$e->getRelatedData())));
                    break;
                case ItemException::ERROR_CHOICE_AMBIGUOUS:
                    $component->inError = self::INSTALL_ERROR_MISSING_DEPENDENCIES;
                    $this->error('module.choice.ambiguous', array($component->getName(), implode(',',$e->getRelatedData())));
                    break;
                case ItemException::ERROR_DEPENDENCY_CANNOT_BE_INSTALLED:
                    $component->inError = self::INSTALL_ERROR_MISSING_DEPENDENCIES;
                    $depName = $e->getRelatedData()->getName();
                    $this->error('module.dependency.error', array($depName, $component->getName()));
                    break;
            }

            $this->ok('configuration.bad.end');
            return false;
        } catch(\Exception $e) {
            $this->error('install.bad.dependencies');
            $this->ok('configuration.bad.end');
            return false;
        }

        $this->ok('install.dependencies.ok');
        return $moduleschain;
    }

    /**
     * Launch the preConfigure method of each modules configurator
     *
     * @param \Jelix\Dependencies\Item[] $moduleschain
     * @return array|bool
     */
    protected function runPreConfigure(&$moduleschain, EntryPoint $entryPoint, $forLocalConfig) {
        $result = true;
        $componentsToInstall = array();
        $installersDisabled = $entryPoint->getConfigObj()->disableInstallers;

        $interactiveCli = null;
        if ($this->consoleOutput && $this->consoleInput) {
            $interactiveCli = new InteractiveConfigurator($this->questionHelper,
                $this->consoleInput, $this->consoleOutput);
        }

        foreach($moduleschain as $resolverItem) {
            /** @var ModuleInstallerLauncher $component */
            $component = $resolverItem->getProperty('component');

            try {
                if ($installersDisabled) {
                    $configurator = null;
                } else {
                    $configurator = $component->getConfigurator(true, $forLocalConfig);
                }
                $componentsToInstall[] = array($configurator, $component);

                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());

                    // setup installation parameters
                    $parameters = $configurator->getDefaultParameters();
                    $parameters = array_merge($parameters, $component->getInstallParameters());
                    if (isset($this->moduleParameters[$component->getName()])) {
                        $parameters = array_merge($parameters, $this->moduleParameters[$resolverItem->getName()]);
                    }
                    $configurator->setParameters($parameters);

                    if ($interactiveCli) {
                        $this->notice('configuration.ask.parameters', array($component->getName()));
                        $configurator->askParameters($interactiveCli);
                    }
                    $component->setInstallParameters($configurator->getParameters());

                    $configurator->preConfigure();
                }
            } catch (Exception $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (\Exception $e) {
                $result = false;
                $this->error ('configuration.module.error', array($component->getName(), $e->getMessage()));
            }
        }
        if (!$result) {
            return false;
        }
        return $componentsToInstall;
    }

    /**
     * @param Item[] $componentsToConfigure
     * @return bool
     */
    protected function runConfigure($componentsToConfigure, EntryPoint $entryPoint) {
        $result = true;
        try {
            foreach($componentsToConfigure as $item) {
                /** @var ModuleInstallerLauncher $component */
                /** @var Module\Configurator $configurator */
                list($configurator, $component) = $item;

                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());
                    $configurator->configure();

                    $component->saveModuleStatus();
                    $this->saveConfigurationFiles($entryPoint);
                }
            }
        } catch (Exception $e) {
            $result = false;
            $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
        } catch (\Exception $e) {
            $result = false;
            $this->error ('configuration.module.error', array($component->getName(), $e->getMessage()));
        }
        return $result;
    }

    protected function runPostConfigure($componentsToConfigure, EntryPoint $entryPoint) {

        $result = true;

        foreach($componentsToConfigure as $item) {
            try {
                /** @var ModuleInstallerLauncher $component */
                /** @var Module\Configurator $configurator */
                list($configurator, $component) = $item;
                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());
                    $configurator->postConfigure();
                    $this->saveConfigurationFiles($entryPoint);
                }
            } catch (Exception $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (\Exception $e) {
                $result = false;
                $this->error ('configurator.module.error', array($component->getName(), $e->getMessage()));
            }
        }
        return $result;
    }

    /**
     * Unconfigure a module
     * @param string the module name
     * @param string $dedicatedEntryPointId entry point from which the module is
     *        mainly accessible
     */
    public function unconfigureModule($moduleName,
                                     $dedicatedEntryPointId = 'index'
    ) {
        $this->startMessage();

        // check that all given modules are existing
        $component = $this->globalSetup->getModuleComponent($moduleName);
        if (!$component) {
            $this->error('module.unknown', $moduleName);
            return false;
        }

        // get all modules
        $resolver = new Resolver();
        foreach($this->globalSetup->getModuleComponentsList() as $name => $module) {
            $resolverItem = $module->getResolverItem();
            if ($name == $moduleName) {
                if ($component->isEnabled()) {
                    $resolverItem->setAction(Resolver::ACTION_REMOVE);
                }
            }
            $resolver->addItem($resolverItem);
        }

        // configure modules
        $modulesChain = $this->resolveDependencies($resolver);
        $modulesToConfigure = array();

        foreach ($modulesChain as $resolverItem) {
            if ($resolverItem->getAction() == Resolver::ACTION_REMOVE) {
                $modulesToConfigure[] = $resolverItem;
            }
        }

        $this->notice('configuration.start');
        $entryPoint = $this->globalSetup->getEntryPointById($dedicatedEntryPointId);
        \jApp::setConfig($entryPoint->getConfigObj());

        if ($entryPoint->getConfigObj()->disableInstallers) {
            $this->notice('install.installers.disabled');
        }

        $componentsToUnconfigure = $this->runPreUnconfigure($modulesToConfigure, $entryPoint);
        if ($componentsToUnconfigure === false) {
            $this->warning('configuration.bad.end');
            return false;
        }

        if (!$this->runUnconfigure($componentsToUnconfigure, $entryPoint)) {
            $this->warning('configuration.bad.end');
            return false;
        }

        $result = $this->runPostUnconfigure($componentsToUnconfigure, $entryPoint);
        if (!$result) {
            $this->warning('configuration.bad.end');
        }
        else {
            $this->ok('configuration.end');
        }
        $this->globalSetup->getUninstallerIni()->save();
        $this->endMessage();
        return $result;
    }



    /**
     * Launch the preUnconfigure method of each modules configurator
     *
     * @param \Jelix\Dependencies\Item[] $moduleschain
     * @return array|bool
     */
    protected function runPreUnconfigure(&$moduleschain, EntryPoint $entryPoint) {
        $result = true;
        $componentsToInstall = array();
        $installersDisabled = $entryPoint->getConfigObj()->disableInstallers;
        foreach($moduleschain as $resolverItem) {
            /** @var ModuleInstallerLauncher $component */
            $component = $resolverItem->getProperty('component');

            try {
                if ($installersDisabled) {
                    $configurator = null;
                } else {
                    $configurator = $component->getConfigurator(false);
                }
                $componentsToInstall[] = array($configurator, $component);

                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());

                    // setup installation parameters
                    $parameters = $configurator->getDefaultParameters();
                    $parameters = array_merge($parameters, $component->getInstallParameters());
                    $configurator->setParameters($parameters);
                    $component->setInstallParameters($parameters);

                    $configurator->preUnconfigure();
                }
            } catch (Exception $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (\Exception $e) {
                $result = false;
                $this->error ('configuration.module.error', array($component->getName(), $e->getMessage()));
            }
        }
        if (!$result) {
            return false;
        }
        return $componentsToInstall;
    }

    /**
     * @param Item[] $componentsToUnconfigure
     * @return bool
     */
    protected function runUnconfigure($componentsToUnconfigure, EntryPoint $entryPoint) {
        $result = true;

        // In $componentsToConfigure, we have the module to unconfigure and
        // all of its reverse dependencies to unconfigure. If none of them have an
        // install script, we don't need to register them into the uninstaller.ini.php
        // and we don't need to backup their uninstall.php script.
        // Else, to uninstall properly the module, we need its uninstall.php script,
        // but also all of its reverse dependencies into uninstaller.ini.php
        $shouldBackupUninstallScript = array_reduce($componentsToUnconfigure,
            function($carry, $item) {
                return $carry | $item[1]->hasUninstallScript();
            }, false);

        try {
            foreach($componentsToUnconfigure as $item) {
                /** @var ModuleInstallerLauncher $component */
                /** @var Module\Configurator $configurator */
                list($configurator, $component) = $item;

                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());
                    $configurator->unconfigure();

                    $component->saveModuleStatus();
                    if ($shouldBackupUninstallScript) {
                        $component->backupUninstallScript();
                    }

                    $this->saveConfigurationFiles($entryPoint);
                }
            }
        } catch (Exception $e) {
            $result = false;
            $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
        } catch (\Exception $e) {
            $result = false;
            $this->error ('configuration.module.error', array($component->getName(), $e->getMessage()));
        }
        return $result;
    }

    protected function runPostUnconfigure($componentsToUnconfigure, EntryPoint $entryPoint) {

        $result = true;

        foreach($componentsToUnconfigure as $item) {
            try {
                /** @var ModuleInstallerLauncher $component */
                /** @var Module\Configurator $configurator */
                list($configurator, $component) = $item;
                if ($configurator) {
                    $this->globalSetup->setCurrentProcessedModule($component->getName());
                    $configurator->postUnconfigure();
                    $this->saveConfigurationFiles($entryPoint);
                }
            } catch (Exception $e) {
                $result = false;
                $this->error ($e->getLocaleKey(), $e->getLocaleParameters());
            } catch (\Exception $e) {
                $result = false;
                $this->error ('configurator.module.error', array($component->getName(), $e->getMessage()));
            }
        }
        return $result;
    }

    protected function saveConfigurationFiles(EntryPoint $entryPoint) {

        // we save the configuration at each module because its
        // configurator may have modified it, and we want to save it
        // in case the next module configurator fails.
        $fullConfig = $this->globalSetup->getFullConfigIni();
        if ($fullConfig->isModified()) {
            //$ep->getLocalConfigIni()->save();
            $fullConfig->save();

            // we re-load configuration file for each module because
            // previous module configurator could have modify it.
            $entryPoint->setConfigObj(
                \jConfigCompiler::read($entryPoint->getConfigFileName(), true,
                    $entryPoint->isCliScript(),
                    $entryPoint->getScriptName()));
            \jApp::setConfig($entryPoint->getConfigObj());
        }
        $this->globalSetup->getUrlModifier()->save();
        $profileIni = $this->globalSetup->getProfilesIni();
        if ($profileIni->isModified()) {
            $profileIni->save();
            \jProfiles::clear();
        }
    }

    protected function startMessage () {
        $this->reporter->start();
    }

    protected function endMessage() {
        $this->reporter->end();
    }

    protected function error($msg, $params=null, $fullString=false){
        if (!$fullString) {
            $msg = $this->messages->get($msg, $params);
        }
        $this->reporter->message($msg, 'error');
    }

    protected function ok($msg, $params=null, $fullString=false){
        if (!$fullString) {
            $msg = $this->messages->get($msg, $params);
        }
        $this->reporter->message($msg, '');
    }

    protected function warning($msg, $params=null, $fullString=false){
        if (!$fullString) {
            $msg = $this->messages->get($msg, $params);
        }
        $this->reporter->message($msg, 'warning');
    }

    protected function notice($msg, $params=null, $fullString=false){
        if (!$fullString) {
            $msg = $this->messages->get($msg, $params);
        }
        $this->reporter->message($msg, 'notice');
    }
}

