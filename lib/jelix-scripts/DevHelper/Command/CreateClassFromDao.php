<?php
/**
 * @package     jelix-scripts
 * @author      Bisse Romain
 * @contributor Laurent Jouanneau
 * @copyright   2009 Bisse Romain, 2016 Laurent Jouanneau
 * @link        http://www.jelix.org
 * @licence     GNU General Public Licence see LICENCE file or http://www.gnu.org/licenses/gpl.html
 */
namespace Jelix\DevHelper\Command;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateClassFromDao extends \Jelix\DevHelper\AbstractCommandForApp {

    protected function configure()
    {
        $this
            ->setName('module:create-class-dao')
            ->setDescription('Allow to create a class into classes directory from a *dao.xml file.')
            ->setHelp('')
            ->addArgument(
                'module',
                InputArgument::REQUIRED,
                'Name of the module'
            )
            ->addArgument(
                'classname',
                InputArgument::REQUIRED,
                'The name of the class to generate'
            )
            ->addArgument(
                'daoname',
                InputArgument::REQUIRED,
                'the name of the dao from which the class will be generated'
            )
        ;
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $module = $input->getArgument('module');
        $daoname = $input->getArgument('daoname');
        $classname = $input->getArgument('classname');

        /*
         * Computing some paths and filenames
         */
        
        $modulePath = $this->getModulePath($module);
        
        $sourceDaoPath = $modulePath.'daos/';
        $sourceDaoPath .= strtolower($daoname).'.dao.xml';
        
        if (!file_exists($sourceDaoPath)) {
            throw new \Exception("The file $sourceDaoPath doesn't exist");
        }
        
        $targetClassPath = $modulePath.'classes/';
        $targetClassPath .= strtolower($classname).'.class.php';
        
        /*
         * Parsing the dao xml file
         */
        
        $selector = new \jSelectorDao($module.'~'.$daoname, '');
        $tools = \jDb::getConnection()->tools();
        
        $doc = new \DOMDocument();
        
        if (!$doc->load($sourceDaoPath)) {
           throw new \jException('jelix~daoxml.file.unknown', $sourceDaoPath);
        }
        if ($doc->documentElement->namespaceURI != JELIX_NAMESPACE_BASE.'dao/1.0') {
           throw new \jException('jelix~daoxml.namespace.wrong',
                                array($sourceDaoPath, $doc->namespaceURI));
        }
        
        $parser = new \jDaoParser($selector);
        $parser->parse(simplexml_import_dom($doc), $tools);
        $properties = $parser->GetProperties();
        
        /*
         * Generating the class
         */
        
        $classContent = '';
        foreach($properties as $name=>$property) {
            $classContent .= "    public \$$name;\n";     
        }
        $this->createFile($targetClassPath,
                          'module/classfromdao.class.tpl',
                          array('properties'=>$classContent,
                                'name'=>$this->_parameters['classname']),
                          "Class");
    }
}

