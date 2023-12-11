# Shopware template copy utility

This package provides a command to copy template files from one template to another. The purpose is to get up and
running with template adjustments as quickly as possible.

## Usage

```bash
bin/console netzarbeiter:template:copy <source> <target>
```

### Options

#### `--mode=<mode>`

Operation mode:
- 'extend' for extending files (using 'sw_extend'); this is the default
- 'override' for overriding files (copy full file contents)

#### `--replace`

Replace existing files in the target plugin.

#### `--dry-run`

Do not install or uninstall plugins, just show what would be done.

## Installation

Make sure Composer is installed globally, as explained in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Applications that use Symfony Flex

Open a command console, enter your project directory and execute:

```console
$ composer require ntzrbtr/shopware-template-copy
```

### Applications that don't use Symfony Flex

#### Step 1: Download the bundle

Open a command console, enter your project directory and execute the following command to download the latest stable
version of this bundle:

```console
$ composer require ntzrbtr/shopware-template-copy
```

#### Step 2: Enable the bundle

Then, enable the bundle by adding it to the list of registered bundles in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Netzarbeiter\Shopware\TemplateCopy\NetzarbeiterShopwareTemplateCopyBundle::class => ['all' => true],
];
```
