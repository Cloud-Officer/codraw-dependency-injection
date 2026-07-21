# Dependency Injection

This package provides addons to the Symfony Dependency Injection component.

## Installation

```bash
composer require codraw/dependency-injection
```

## Integration

The `Draw\Component\DependencyInjection\Integration` namespace contains classes that can be used to easily integrate
subcomponents into a main bundle.

An example of this is all the draw components that are integrated into the `DrawFrameworkExtraBundle`.

When creating the main bundle extension you can use the `ExtendableExtensionTrait` to easily integrate all the subcomponents.

```php

namespace Example\Bundle\MyBundle\DependencyInjection;

use Draw\Component\DependencyInjection\Integration\ExtendableExtensionTrait;
use Example\Component\MyComponent\DependencyInjection\MyComponentIntegration;
use Example\Component\MyOtherComponent\DependencyInjection\MyOtherComponentIntegration;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

class ExampleMyBundleExtension extends Extension implements PrependExtensionInterface
{
    use ExtendableExtensionTrait;

    public function __construct()
    {
        $this->registerDefaultIntegrations();
    }

    private function provideExtensionClasses(): array
    {
        return [
            MyComponentIntegration::class,
            MyOtherComponentIntegration::class,
        ];
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration($this->integrations);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->loadIntegrations($configs, $container);

        // Do your bundle specific configuration here
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependIntegrations($container, 'example_my_bundle');
    }
}
```

### registerDefaultIntegrations

The `registerDefaultIntegrations` method will automatically register all the integrations that are in the `provideExtensionClasses` method.

It will check if the class exists and if it does it will create a new instance of it and add it to the `integrations` property.

That way you can define the integration classes in the specific component, and it will automatically be integrated into the main bundle
if your component is installed.

### loadIntegrations

The `loadIntegrations` method will call the `load` method on all the integrations that are registered.

It will automatically pass the configuration to the existing configuration only if they are `enabled`.

### prependIntegrations

The `prependIntegrations` method will call the `prepend` method on all the integrations that are registered.

It will check if the configuration is `enabled` and if it is it will call the `prepend` method.

### Configuration

Here is an example of configuration base on the example above.

```yaml
example_my_bundle:
    my_component:
        enabled: true
        my_component_configuration: true
    my_other_component:
        enabled: false
```

This example will enable the `MyComponentIntegration` and disable the `MyOtherComponentIntegration`.
