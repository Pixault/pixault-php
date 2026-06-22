# pixault/pixault-php

PHP SDK for the [Pixault](https://pixault.io) image processing CDN and API.

## Requirements

- PHP 8.1+
- cURL extension

## Installation

```bash
composer require pixault/pixault-php
```

## Quick Start

```php
use Pixault\Pixault;

$pixault = new Pixault([
    'base_url' => 'https://img.pixault.io',
    'default_project' => 'my-project',
    'api_key' => 'pk_your_secret_key',
]);

// Generate an optimized image URL
$url = $pixault->image('img_01JKABC')
    ->width(800)
    ->height(600)
    ->fit('cover')
    ->quality(85)
    ->format('webp')
    ->build();
// => "https://img.pixault.io/my-project/img_01JKABC/w_800,h_600,fit_cover,q_85.webp"

// Upload an image
$result = $pixault->upload('my-project', '/path/to/photo.jpg');
echo $result['imageId'];

// List images
$images = $pixault->listImages('my-project', ['category' => 'hero']);

// Get metadata
$meta = $pixault->getMetadata('my-project', 'img_01JKABC');
```

## URL Builder

```php
// Named transform with overrides
$url = $pixault->image('my-project', 'img_01JKABC')
    ->transform('gallery')
    ->width(400)
    ->build();

// Watermark
$url = $pixault->image('img_01JKABC')
    ->width(1200)
    ->watermark('logo', 'br', 30)
    ->build();
```

## Configuration

| Option | Description | Required |
|--------|-------------|----------|
| `base_url` | Pixault CDN base URL | Yes |
| `default_project` | Default project ID | No |
| `api_key` | API secret key (`pk_...`) | No |

## Documentation

Full documentation at [pixault.dev](https://pixault.dev).

## License

[MIT](LICENSE)
