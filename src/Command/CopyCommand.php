<?php

declare(strict_types=1);

namespace Netzarbeiter\Shopware\TemplateCopy\Command;

use Composer\IO\NullIO;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;
use Shopware\Core\Framework\Plugin\PluginService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copy template files from one plugin to another
 */
class CopyCommand extends \Symfony\Component\Console\Command\Command
{
    protected const ARGUMENT_SOURCE = 'source';
    protected const ARGUMENT_TARGET = 'target';
    protected const OPTION_MODE = 'mode';
    protected const MODE_EXTEND = 'extend';
    protected const MODE_OVERRIDE = 'override';
    protected const OPTION_REPLACE = 'replace';
    protected const OPTION_DRY_RUN = 'dry-run';

    /**
     * @inerhitDoc
     */
    protected static $defaultName = 'netzarbeiter:template:copy';

    /**
     * @inerhitDoc
     */
    protected static $defaultDescription = 'Copy template files from one plugin to another';

    /**
     * Style for input/output
     *
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     * Working directory
     *
     * @var string
     */
    protected string $workingDir;

    /**
     * CopyCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->workingDir = getcwd();
    }

    /**
     * @inerhitDoc
     */
    protected function configure(): void
    {
        $modeExtend = self::MODE_EXTEND;
        $modeOverride = self::MODE_OVERRIDE;
        $modeDescription = "Mode: '$modeExtend' for extending files (using 'sw_extend'), '$modeOverride' for overriding files (copy full file contents)";

        $this
            ->addArgument(
                self::ARGUMENT_SOURCE,
                InputArgument::REQUIRED,
                'Path to source plugin'
            )
            ->addArgument(
                self::ARGUMENT_TARGET,
                InputArgument::REQUIRED,
                'Path to target plugin'
            )
            ->addOption(
                self::OPTION_MODE,
                null,
                InputOption::VALUE_REQUIRED,
                $modeDescription,
                self::MODE_EXTEND
            )
            ->addOption(
                self::OPTION_REPLACE,
                null,
                InputOption::VALUE_NONE,
                'Replace existing files'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Dry run, do not touch files'
            );
    }

    /**
     * @inerhitDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * @inerhitDoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Print plugin title.
        $this->io->title(sprintf('%s (%s)', $this->getDescription(), $this->getName()));

        // Check source path.
        $sourcePath = $input->getArgument(self::ARGUMENT_SOURCE);
        if (!is_dir($sourcePath)) {
            $this->io->error(sprintf('Source path "%s" is not a directory', $sourcePath));
            return self::FAILURE;
        }
        $sourcePath = realpath($sourcePath);

        // Check target path.
        $targetPath = $input->getArgument(self::ARGUMENT_TARGET);
        if (!is_dir($targetPath)) {
            $this->io->error(sprintf('Target path "%s" is not a directory', $targetPath));
            return self::FAILURE;
        }
        $targetPath = realpath($targetPath);

        // Check if source and target are different.
        if ($sourcePath === $targetPath) {
            $this->io->error('Source and target are the same');
            return self::FAILURE;
        }

        // Check mode.
        $mode = $input->getOption(self::OPTION_MODE);
        if (!in_array($mode, [self::MODE_EXTEND, self::MODE_OVERRIDE], true)) {
            $this->io->error(sprintf('Invalid mode "%s"', $mode));
            return self::FAILURE;
        }

        // Print warning on dry run.
        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $this->io->warning('Dry run, no files will be touched');
        }

        // Find all template files in target.
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files()->in($sourcePath . '/src/Resources/views/storefront/')->name('*.html.twig');

        // Walk through all template files and handle them.
        $rows = [];
        foreach ($finder as $sourceFile) {
            // Get target file.
            $targetFile = str_replace($sourcePath, $targetPath, $sourceFile->getPathname());

            // Get action for display.
            $action = match ($mode) {
                self::MODE_EXTEND => 'Extend',
                self::MODE_OVERRIDE => 'Override',
            };
            if (file_exists($targetFile)) {
                if ($input->getOption(self::OPTION_REPLACE)) {
                    $action .= ' (replaced)';
                } else {
                    $action .= ' (skipped)';
                }
            }

            // Perform action.
            if (!$input->getOption(self::OPTION_DRY_RUN)) {
                // Check if target file exists.
                if (!file_exists($targetFile) || $input->getOption(self::OPTION_REPLACE)) {
                    match ($mode) {
                        self::MODE_EXTEND => $this->extend($targetFile, $sourceFile->getPathname(), $sourcePath),
                        self::MODE_OVERRIDE => $this->override($targetFile, $sourceFile->getPathname(), $sourcePath),
                    };
                }
            }

            // Collect table row.
            $rows[] = [
                $this->stripWorkingDir($sourceFile->getPathname()),
                $this->stripWorkingDir($targetFile),
                $action,
            ];
        }
        $this->io->table(['Source', 'Target', 'Action'], $rows);

        return self::SUCCESS;
    }

    /**
     * Strip the working directory from the given path.
     *
     * @param string $path
     * @return string
     */
    protected function stripWorkingDir(string $path): string
    {
        return str_replace($this->workingDir . '/', '', $path);
    }

    /**
     * Extend template file.
     *
     * @param string $target
     * @param string $source
     * @param string $base
     */
    protected function extend(string $target, string $source, string $base): void
    {
        $this->ensureDirectoryExists(dirname($target));

        $path = str_replace($base . '/src/Resources/views/storefront/', '', $source);
        $content = <<<EOT
{# This file was generated by the netzarbeiter:template:copy command #}

{% sw_extends '@Storefront/storefront/$path' %}

EOT;
        file_put_contents($target, $content);
    }

    /**
     * Override template file.
     *
     * @param string $target
     * @param string $source
     * @param string $base
     */
    protected function override(string $target, string $source, string $base): void
    {
        $this->ensureDirectoryExists(dirname($target));

        $original = file_get_contents($source);
        $path = str_replace(dirname($base) . '/', '', $source);
        $content = <<<EOT
{# This file was generated by the netzarbeiter:template:copy command #}

{# Original path: $path #}

$original

EOT;
        file_put_contents($target, $content);
    }

    /**
     * Ensure that the given directory exists.
     *
     * @param string $directory
     */
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
    }
}
