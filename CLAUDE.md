# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Quality Assurance
```bash
# Code style fixing
composer cs
# Static analysis
composer phpstan
# Rector refactoring
composer rector
```

### Main Store Assembler Script
```bash
# Full build and deploy
./bin/sylius-store-assembler

# Build only (plugins, fixtures, themes)
./bin/sylius-store-assembler --build

# Deploy only (DB operations, fixtures loading)
./bin/sylius-store-assembler --deploy

# Download store preset
./bin/sylius-store-assembler --get-preset <preset-name>
```

### Plugin Management
```bash
# Interactive plugin installation
php bin/console sylius:plugins:install

# Install specific plugin
php bin/console sylius:plugins:install sylius/adyen-plugin --plugin-version=2.0

# List available plugins
php bin/console sylius:store-assembler:plugin:list
```

## Architecture Overview

### Core Components

**Sylius Store Assembler** is a Symfony bundle that automates Sylius e-commerce store configuration using two main approaches:

### 1. Store Preset Approach (Primary)
- **Configuration**: `store-preset/store-preset.json` - main configuration file defining plugins, themes, and fixtures
- **Structure**:
  ```
  store-preset/
  ├── store-preset.json    # Main config: plugins, themes, fixtures
  ├── fixtures/fixtures.yaml
  └── themes/
  ```
- **Plugin Workflow**:
  1. `sylius:store-assembler:plugin:prepare` - Composer installation, stability config
  2. `sylius:store-assembler:plugin:install` - Manifest processing, configurators

### 2. CLI Interactive Approach (Alternative)
- Direct command execution: `sylius:plugins:install <package>`
- Same underlying workflow as store preset approach
- Interactive plugin selection from catalog

### Plugin Manifest System

Each plugin requires a manifest at `config/plugins/{vendor}/{name}/{version}/manifest.json`:

```json
{
  "type": "open-source|paid",
  "minimum-stability": "dev",
  "rector-sets": ["Sylius\\SyliusRector\\Set\\..."],
  "steps": ["shell command"],
  "configurators": [{"class": "ConfiguratorClass"}]
}
```

### Key Classes

- **`PluginWorkflow`** - Central orchestration of plugin installation
  - `prepare()` - Composer operations, stability, repositories
  - `install()` - Manifest processing, configurators execution
- **`PluginCatalog`** - Plugin discovery from manifest files
- **`ConfigTrait`** - Shared configuration reading logic
- **`PluginDefinition`** - Plugin metadata container

### Command Architecture Patterns

**Common Logic Pattern** across `PluginInstallCommand`, `PluginPrepareCommand`, `PluginInstallInteractiveCommand`:

1. **Configuration Reading**: Uses `ConfigTrait.getPlugins()` to read from store-preset
2. **Workflow Delegation**: Delegates to `PluginWorkflow.prepare()` or `PluginWorkflow.install()`
3. **Error Handling**: Consistent RuntimeException catching and user messaging

**Potential Refactoring Opportunities**:
- Extract `AbstractPluginCommand` base class
- Centralize configuration logic in `ConfigurationManager`
- Create `ComposerManager` for package operations
- Separate `ManifestProcessor` for manifest handling

### Store Assembly Process

The full store assembly follows this sequence:

1. **BUILD Phase**:
   - Plugin preparation (Composer install)
   - Plugin installation (manifest processing)
   - Fixture preparation
   - Theme preparation

2. **DEPLOY Phase**:
   - Database recreation
   - Schema updates
   - Fixture loading

### Bundle Structure

- **Commands**: Console commands for plugin/fixture/theme operations
- **Plugin**: Core plugin management (catalog, workflow, definitions)
- **Configurator**: Plugin configuration application
- **Message/MessageHandler**: Async plugin installation support
- **Service**: Installation state management
- **Util**: Utility classes (manifest location)

### Development Notes

- All classes marked `@experimental` - API may change
- Uses Symfony Process component for shell command execution
- Supports both open-source and paid plugins with repository authentication
- Integrates with Rector for automated code updates
- Messenger integration for async plugin installation

## Refactoring Recommendations

### Current Architecture Issues

1. **PluginWorkflow is monolithic**:
   - `prepare()` method: 257 lines mixing Composer, Rector, repositories
   - `install()` method: 70+ lines handling manifests and configurators
   - Multiple responsibilities in single class

2. **ConfigTrait anti-pattern**:
   - Mixes configuration reading with business logic
   - Hardcoded coupling to `store-preset.json`
   - Difficult to test and extend

### Proposed Step-Based Workflow (GitHub Actions-like)

Replace monolithic `PluginWorkflow` with step-based orchestrator:

```php
interface WorkflowStepInterface
{
    public function getName(): string;
    public function execute(StepContext $context): StepResult;
    public function canRun(StepContext $context): bool;
}

class PluginWorkflowOrchestrator
{
    private const PREPARE_STEPS = [
        AdjustComposerStabilityStep::class,
        ConfigureComposerRepositoryStep::class,
        InstallOpenSourcePluginsStep::class,
        InstallPaidPluginsStep::class,
        ProcessRectorConfigStep::class,
    ];
    
    private const INSTALL_STEPS = [
        ValidateManifestsStep::class,
        ExecuteShellCommandsStep::class,
        RunConfiguratorsStep::class,
    ];
}
```

### Replace ConfigTrait with Configuration Provider

```php
interface ConfigurationProviderInterface
{
    public function getPlugins(): array;
    public function getThemes(): array;
    public function getFixtures(): array;
}

class StorePresetConfigurationProvider implements ConfigurationProviderInterface
{
    // Clean separation of concerns
    // Easy to add DatabaseConfigurationProvider, YamlConfigurationProvider
}
```

### Benefits of Step-Based Architecture

- **Single Responsibility**: Each step has one clear purpose
- **Testability**: Easy to mock and unit test individual steps
- **Conditional Execution**: `canRun()` allows for smart step skipping
- **Extensibility**: Add new steps without modifying existing code
- **Debugging**: Clear boundaries for error identification
- **Reusability**: Steps can be reused across different workflows