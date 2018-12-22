<?php
/**
 * @package     jelix-scripts
 * @author Laurent Jouanneau
 * @copyright   2018 Laurent Jouanneau
 * @link        https://jelix.org
 * @licence     GNU General Public Licence see LICENCE file or http://www.gnu.org/licenses/gpl.html
 */
namespace Jelix\Scripts;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InstallerCommand extends Command {

    protected function configure()
    {
        $this
            ->setName('installer')
            ->setDescription('Launch installers of the application')
            ->setHelp('')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        \jAppManager::close();

        if ($output->isVerbose()) {
            $reporter = new \Jelix\Installer\Reporter\Console($output, 'notice', 'Installation');
        }
        else {
            $reporter = new \Jelix\Installer\Reporter\Console($output, 'error', 'Installation');
        }

        $installer = new \Jelix\Installer\Installer($reporter);
        if (!$installer->installApplication()) {
            return 1;
        }

        try {
            \jAppManager::clearTemp();
        }
        catch(\Exception $e) {
            $output->writeln("<comment>WARNING: temporary files cannot be deleted because of this error: ".$e->getMessage()."</comment>");
            $output->writeln("<comment>WARNING: Delete temp files by hand immediately, then run the command</comment> <fg=cyan>console.php app:open</>");
            return 1;
        }
        \jAppManager::open();
        return 0;
    }
}
