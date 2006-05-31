<?php
/**
* @package     jelix-modules
* @subpackage  acl
* @version     $Id$
* @author      Jouanneau Laurent
* @contributor
* @copyright   2006 Jouanneau laurent
* @link        http://www.jelix.org
* @licence     GNU General Public Licence see LICENCE file or http://www.gnu.org/licenses/gpl.html
*/

class ListenerAcl extends jEventListener{

   function onFetchXulOverlay($event){
        if($event->getParam('tpl') == 'xulapp~main'){
            $event->Add('acl~xuladmin_xaovlay');
        }
   }
}
?>