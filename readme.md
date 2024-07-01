# laravel-geo

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Travis](https://img.shields.io/travis/ionutgrecu/laravel-geo.svg?style=flat-square)]()
[![Total Downloads](https://img.shields.io/packagist/dt/ionutgrecu/laravel-geo.svg?style=flat-square)](https://packagist.org/packages/ionutgrecu/laravel-geo)

## Install
`composer require ionutgrecu/laravel-geo`

## Usage
This package facilitates the creation of geo models and migrations. Model relationships are established using codes to ensure compatibility across various systems utilizing this package. When referencing models in your code, always use their codes instead of their IDs.

Additionally, you can import specific regions or all available regions into the database as needed.

``` code
php artisan geo:import-regions {regions? : Comma separated list of regions to import. Ex.: eu,na. Default: all regions.} {--c|countries : Import countries.}
```

## Todo
- [ ] Add support for importing cities
- [ ] Further develop the GeoService class

## Credits

- [Ionut Grecu](https://github.com/ionutgrecu)
- [All Contributors](https://github.com/ionutgrecu/laravel-geo/contributors)

## Security
If you discover any security-related issues, please email ionut@grecu.eu instead of using the issue tracker.

## License
The MIT License (MIT). Please see [License File](/LICENSE.md) for more information.