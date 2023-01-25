# Convert tangram tiles to 16 bit grayscale PNG

## Requirements

 * PHP >7.2
   * Composer
 * Python3
   * numpy
   * cupy (optional)
   * PIL
   
## Installation

```composer install```

## Usage

For all options use

```php renderLocation.php --help```

Example command

```php renderLocation.php -a YOUR_API_KEY --latitude 12.345 --longitude 123.456 --zoom 15 -x 5 -y 5 -e```

## Examples

The examples in this gist are generated using the following command:

```php renderLocation.php -a YOUR_API_KEY -l 36.6271 -k 138.20453 -x 8 -y 8 -z 13 -e```

On my XPS 15 9350 with i7 and 32GB ram, aside from downloading, the process takes around 15s to complete for this example.