<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Sylius\StoreAssemblerBundle\Plugin\Service\ComposerMetadataResolver;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Symfony\Component\Process\Process;

/** @experimental */
final class InstallOpenSourcePluginsStep extends AbstractPluginDefinitionsStep implements PrepareStepInterface
{
    public function __construct(
        private readonly ComposerMetadataResolver $composerMetadataResolver,
        private readonly string $projectDir,
        PluginDefinitionResolver $definitionResolver,
    ) {
        parent::__construct($definitionResolver);
    }

    public function run(StepContext $context): void
    {
        $definitions = $this->definitions($context);

        $openSource = array_filter(
            $definitions,
            static fn (PluginDefinition $definition): bool => !$definition->isPaid(),
        );

        if ($openSource === []) {
            return;
        }

        $io = $context->io();
        $output = $context->output();
        $projectDir = $this->projectDir;
        $stabilityFlags = $this->composerMetadataResolver->collectStabilityFlags($definitions);

        $io->section('[Plugin Preparer] Installing open-source plugins');
        foreach ($openSource as $definition) {
            $version = $context->plugins()[$definition->package] ?? $definition->version;
            $io->text(sprintf(' → %s:%s', $definition->package, $version));

            $constraint = "{$definition->package}:^{$version}";
            if (isset($stabilityFlags[$definition->package])) {
                $constraint .= '@' . $stabilityFlags[$definition->package];
            }

            (new Process(
                ['composer', 'require', $constraint, '--no-scripts', '--no-interaction'],
                $projectDir
            ))
                ->setTimeout(0)
                ->mustRun(static fn ($type, $buffer) => $output->write($buffer));
        }
    }
}
