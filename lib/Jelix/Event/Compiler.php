<?php
/**
 * @author      Laurent Jouanneau
 * @copyright 2005-2014 Laurent Jouanneau
 *
 * @see        http://www.jelix.org
 * @licence  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public Licence, see LICENCE file
 */

namespace Jelix\Event;

use Jelix\Core\App;

class Compiler implements \Jelix\Core\Includer\MultiFileCompilerInterface
{
    private $eventList;

    public function __construct()
    {
        $this->eventList = array();
    }

    public function compileItem($sourceFile, $module)
    {
        if (is_readable($sourceFile)) {
            $xml = simplexml_load_file($sourceFile);

            $config = App::config()->disabledListeners;
            if (isset($xml->listener)) {
                foreach ($xml->listener as $listener) {
                    $listenerName = (string) $listener['name'];
                    $selector = $module.'~'.$listenerName;
                    foreach ($listener->event as $eventListened) {
                        $name = (string) $eventListened['name'];
                        if (isset($config[$name])) {
                            if (is_array($config[$name])) {
                                if (in_array($selector, $config[$name])) {
                                    continue;
                                }
                            } elseif ($config[$name] == $selector) {
                                continue;
                            }
                        }
                        // key = event name ,  value = list of file listener
                        $this->eventList[$name][] = array($module, $listenerName);
                    }
                }
            }
        }

        return true;
    }

    public function endCompile($cachefile)
    {
        $content = '<?php return '.var_export($this->eventList, true).";\n?>";
        \jFile::write($cachefile, $content);
    }
}
