# Convert tangram tiles to 16 bit grayscale PNG

## Requirements

 * PHP >7.2
   * Composer
 * Python3
   * numpy
   * cupy (optional - only use if you have a GPU, not recommended as it's not much faster than numpy tbh)
   * PIL
   
## Installation

You will need to register for an API key here:

[https://developers.nextzen.org/](https://developers.nextzen.org/)

Then navigate to the project directory and run composer install:

```
cd Nextzen16bitGrayscaleHeightmap
composer install
```

## Usage

For all options use

```php renderLocation.php --help```

Any option can be defaulted in the given  ```config.php``` file.

You can store commonly used locations under a key in ```locations.config.php```

Command line args take precedence over conf options, so you can for example specify a different tile width or zoom level

Example command

```php renderLocation.php -a YOUR_API_KEY --latitude 12.345 --longitude 123.456 --zoom 15 -x 5 -y 5 -e```

```php renderLocation.php -a YOUR_API_KEY --location central_london -e```

## Examples

The examples in this gist are generated using the following command:

```php renderLocation.php -a YOUR_API_KEY -l 36.6271 -k 138.20453 -x 8 -y 8 -z 13 -e```

On my XPS 15 9350 with i7 and 32GB ram, aside from downloading, the process takes around 15s to complete for this example.

### 8 bit RGB output

![Example Output Colour](example_output_rgb.png)

### 16 bit grayscale output

![Example Output Grayscale](example_output_grayscale.png)
