# Client for SimpleStats!

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
[![Tests](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml)
[![Check & fix styling](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml)
[![License](https://img.shields.io/packagist/l/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
<!--[![Total Downloads](https://img.shields.io/packagist/dt/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)-->

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require simplestats-io/laravel-client
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="simplestats-client-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="simplestats-client-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="simplestats-client-views"
```

## Usage

```php
$simplestatsClient = new SimpleStatsIo\LaravelClient();
echo $simplestatsClient->echoPhrase('Hello, SimpleStatsIo!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Zacharias Creutznacher](https://github.com/Zacharias Creutznacher)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
