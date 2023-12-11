<?php

declare(strict_types=1);

namespace Netzarbeiter\Shopware\TemplateCopy\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\PluginNotFoundException;
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
     * Context
     *
     * @var Context
     */
    protected Context $context;

    /**
     * Style for input/output
     *
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     * CopyCommand constructor.
     *
     * @param PluginService $pluginService
     */
    public function __construct(protected PluginService $pluginService)
    {
        parent::__construct();

        // Create context.
        $this->context = Context::createDefaultContext();
        $this->context->addState(
            \Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry::DISABLE_INDEXING
        );
    }

    /**
     * @inerhitDoc
     */
    protected function configure(): void
    {
        $modeExtend = self::MODE_EXTEND;
        $modeOverride = self::MODE_OVERRIDE;
        $modeDescription = "Mode: '$modeExtend' for extending files (using 'sw_extends'), '$modeOverride' for overriding files (copy full file contents)";

        $this
            ->addArgument(
                self::ARGUMENT_SOURCE,
                InputArgument::REQUIRED,
                'Source plugin'
            )
            ->addArgument(
                self::ARGUMENT_TARGET,
                InputArgument::REQUIRED,
                'Target plugin'
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

        // Check source plugin.
        $sourceName = $input->getArgument(self::ARGUMENT_SOURCE);
        try {
            $sourcePlugin = $this->pluginService->getPluginByName($sourceName, $this->context);
        } catch (PluginNotFoundException $e) {
            $this->io->error(sprintf('Source plugin "%s" not found', $sourceName));
            return self::FAILURE;
        }
        $sourcePath = $sourcePlugin->getPath();

        // Check target plugin.
        $targetName = $input->getArgument(self::ARGUMENT_TARGET);
        try {
            $targetPlugin = $this->pluginService->getPluginByName($targetName, $this->context);
        } catch (PluginNotFoundException $e) {
            $this->io->error(sprintf('Target plugin "%s" not found', $targetName));
            return self::FAILURE;
        }
        $targetPath = $targetPlugin->getPath();

        // Check if source and target are different.
        if ($sourcePlugin->getId() === $targetPlugin->getId()) {
            $this->io->error('Source and target are the same');
            return self::FAILURE;
        }

        // Check mode.
        $mode = $input->getOption(self::OPTION_MODE);
        if (!in_array($mode, [self::MODE_EXTEND, self::MODE_OVERRIDE], true)) {
            $this->io->error(sprintf('Invalid mode "%s"', $mode));
            return self::FAILURE;
        }

        // Print summary of what is to be done.
        $this->io->definitionList(
            ['Source' => $sourcePlugin->getName()],
            ['Target' => $targetPlugin->getName()],
            ['Mode' => ucfirst($mode)],
            ['Replace?' => $input->getOption(self::OPTION_REPLACE) ? 'Yes' : 'No']
        );

        // Print warning on dry run.
        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $this->io->warning('Dry run, no files will be touched');
        }

        // Find all template files in source plugin.
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->files()->in($sourcePath . '/src/Resources/views/storefront/')->name('*.html.twig');

        // Walk through all template files and handle them.
        $rows = [];
        foreach ($finder as $sourceFile) {
            // Get target file.
            $targetFile = str_replace($sourcePath, $targetPath, $sourceFile->getPathname());

            // Is the file being replaced, skipped or none of those?
            $action = match(true) {
                file_exists($targetFile) && !$input->getOption(self::OPTION_REPLACE) => 'Skipped',
                file_exists($targetFile) && $input->getOption(self::OPTION_REPLACE) => 'Replaced',
                default => 'Copied',
            };

            // Handle the file.
            if (!$input->getOption(self::OPTION_DRY_RUN)) {
                // Check if target file exists.
                if (!file_exists($targetFile) || $input->getOption(self::OPTION_REPLACE)) {
                    match ($mode) {
                        self::MODE_EXTEND => $this->extend($targetFile, $sourceFile->getPathname(), $sourcePlugin),
                        self::MODE_OVERRIDE => $this->override($targetFile, $sourceFile->getPathname(), $sourcePlugin),
                    };
                }
            }

            // Collect table row.
            $rows[] = [
                $this->getPluginPath($sourcePlugin, $sourceFile->getPathname()),
                $this->getPluginPath($targetPlugin, $targetFile),
                $action,
            ];
        }
        $this->io->table(['Source', 'Target', 'Action'], $rows);

        return self::SUCCESS;
    }

    /**
     * Get path inside of plugin in template style.
     *
     * @param \Shopware\Core\Framework\Plugin\PluginEntity $plugin
     * @param string $path
     * @return string
     */
    protected function getPluginPath(\Shopware\Core\Framework\Plugin\PluginEntity $plugin, string $path): string
    {
        return sprintf(
            '@%s/storefront/%s',
            $plugin->getName(),
            str_replace($plugin->getPath() . '/src/Resources/views/storefront/', '', $path)
        );
    }

    /**
     * Extend template file.
     *
     * @param string $target
     * @param string $source
     * @param \Shopware\Core\Framework\Plugin\PluginEntity $base
     */
    protected function extend(string $target, string $source, \Shopware\Core\Framework\Plugin\PluginEntity $base): void
    {
        $this->ensureDirectoryExists(dirname($target));

        $path = str_replace('@' . $base->getName(), '@Storefront', $this->getPluginPath($base, $source));
        $content = <<<EOT
{# This file was generated by the netzarbeiter:template:copy command #}

{% sw_extends '$path' %}

EOT;
        file_put_contents($target, $content);
    }

    /**
     * Override template file.
     *
     * @param string $target
     * @param string $source
     * @param \Shopware\Core\Framework\Plugin\PluginEntity $base
     */
    protected function override(string $target, string $source, \Shopware\Core\Framework\Plugin\PluginEntity $base): void
    {
        $this->ensureDirectoryExists(dirname($target));

        $original = file_get_contents($source);
        $path = $this->getPluginPath($base, $source);
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
