<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/*
  Cleanup command:
  - remove chunk sessions older than 30 minutes
  - remove final files older than 30 days if retention needed
*/

class CleanupChunksCommand extends Command
{
    protected static $defaultName = 'app:cleanup-uploads';
    private $projectDir;
    private $fs;

    public function __construct(string $projectDir)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->fs = new Filesystem();
    }

    protected function configure()
    {
        $this->setDescription('Cleanup stale upload chunks and old final files');
    }

    // Note the return type ': int' required by modern Symfony Command::execute signature
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chunksDir = $this->projectDir . '/public/uploads/chunks';
        $finalDir = $this->projectDir . '/public/uploads/final';

        $now = time();
        if (is_dir($chunksDir)) {
            foreach (scandir($chunksDir) as $entry) {
                if (in_array($entry, ['.','..'])) {
                    continue;
                }
                $path = $chunksDir . '/' . $entry;
                $mod = @filemtime($path);
                // 30 minutes timeout
                if ($mod !== false && ($now - $mod > 30 * 60)) {
                    $this->fs->remove($path);
                    $output->writeln("Removed stale chunk folder: $entry");
                }
            }
        }

        // final files cleanup: 30 days retention
        if (is_dir($finalDir)) {
            foreach (scandir($finalDir) as $dateDir) {
                if (in_array($dateDir, ['.','..'])) {
                    continue;
                }
                $dpath = $finalDir . '/' . $dateDir;
                if (!is_dir($dpath)) {
                    continue;
                }
                foreach (new \DirectoryIterator($dpath) as $f) {
                    if ($f->isDot()) {
                        continue;
                    }
                    $filePath = $f->getPathname();
                    if ($now - filemtime($filePath) > 30 * 24 * 3600) {
                        $this->fs->remove($filePath);
                        $output->writeln("Removed old final file: {$f->getFilename()}");
                    }
                }
            }
        }

        // return success status code expected by Symfony
        return Command::SUCCESS;
    }
}
