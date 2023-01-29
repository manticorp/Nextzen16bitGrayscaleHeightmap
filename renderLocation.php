<?php

/**
 * CLI tool that downloads a series of tiles from the terrarium API
 *
 * @author  Harry Mustoe-Playfair <h@hmp.is.it>
 * @license MIT https://opensource.org/licenses/MIT
 */

require 'vendor/autoload.php';

$defaultConfig = [
    'cachedir' => 'cache',
    'python' => 'python',
    'tiledimension' => 256,
    'outputdir' => 'output',
];
$locations = [];

if (file_exists('locations.config.php')) {
    $locations += include "locations.config.php";
}

if (file_exists('config.php')) {
    $cfgFile = include 'config.php';
    $defaultConfig = $cfgFile + $defaultConfig;
}

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use League\CLImate\CLImate;
use Commando\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

$client = new Client();
$cli    = new CLImate();
$cmd    = new Command();

$cmd->option('location')
    ->describedAs('Use a named location from location.conf.php - all params can be overridden on the command line args.');

$cmd->option('latitude')
    ->aka('l')
    ->describedAs('The latitude for the center point for your export');

$cmd->option('longitude')
    ->aka('k')
    ->describedAs('The longitude for the center point for your export');

$cmd->option('zoom')
    ->aka('z')
    ->describedAs('The zoom level of your export');

$cmd->option('tilesx')
    ->aka('x')
    ->describedAs('The number of tiles to output in the +/- x direction. This does not include the center tile, e.g. a value of 1 will be 3 tiles in width.');

$cmd->option('tilesy')
    ->aka('y')
    ->describedAs('The number of tiles to output in the +/- y direction. This does not include the center tile, e.g. a value of 1 will be 3 tiles in height.');

$cmd->option('apikey')
    ->aka('a')
    ->required()
    ->describedAs('Nextzen api key');

$cmd->option('python')
    ->aka('p')
    ->describedAs('Location of python binary');

$cmd->option('cachedir')
    ->aka('c')
    ->describedAs('The cache directory to use');

$cmd->option('outputdir')
    ->aka('o')
    ->describedAs('The output directory to use');

$cmd->option('tiledimension')
    ->aka('t')
    ->describedAs('The width/height of the outputted tiles. Will be 256 in most cases I believe.');

$cmd->option('executepython')
    ->aka('e')
    ->boolean()
    ->describedAs('Whether to execute python scripts, by default just shows the commands');

$cmd->option('verbose')
    ->aka('v')
    ->boolean()
    ->describedAs('Verbose output');

$location = [
    'latitude'  => null,
    'longitude' => null,
    'zoom'      => null,
    'tilesx'    => null,
    'tilesy'    => null,
];

foreach ($defaultConfig as $key => $value) {
    if ($cmd->hasOption($key) && !array_key_exists($key, $location)) {
        $cmd->getOption($key)->setDefault($value);
    }
}

$apiKey    = $cmd['apikey'];
$url       = "https://s3.amazonaws.com/elevation-tiles-prod/terrarium/%d/%d/%d.png?api_key=%s";
$cacheDir  = $cmd['cachedir'];
$outputDir = $cmd['outputdir'];

if (!empty($outputDir)) {
    $outputDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputDir);
    $outputDir = finish($outputDir, DIRECTORY_SEPARATOR);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
}

$tileWidth = $cmd['tiledimension'];

$location['latitude'] = $cmd['latitude'];
$location['longitude'] = $cmd['longitude'];
$location['zoom'] = $cmd['zoom'];
$location['tilesx'] = $cmd['tilesx'];
$location['tilesy'] = $cmd['tilesy'];

if ($cmd['location'] && array_key_exists($cmd['location'], $locations)) {
    $location['latitude']  = $location['latitude'] ?? $locations[$cmd['location']]['latitude'] ?? $defaultConfig['latitude'];
    $location['longitude'] = $location['longitude'] ?? $locations[$cmd['location']]['longitude'] ?? $defaultConfig['longitude'];
    $location['zoom']      = $location['zoom'] ?? $locations[$cmd['location']]['zoom'] ?? $defaultConfig['zoom'] ?? 10;
    $location['tilesx']    = $location['tilesx'] ?? $locations[$cmd['location']]['tilesx'] ?? $defaultConfig['tilesx'] ?? 10;
    $location['tilesy']    = $location['tilesy'] ?? $locations[$cmd['location']]['tilesy'] ?? $defaultConfig['tilesy'] ?? 10;
}

if (empty($location['latitude'])) {
    throw new Exception('No latitude given');
}

if (empty($location['longitude'])) {
    throw new Exception('No longitude given');
}

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

/**
 * Cap a string with a single instance of a given value.
 *
 * @param string $value The value to end
 * @param string $cap   The value to end with
 *
 * @return string
 */
function finish($value, $cap)
{
    $quoted = preg_quote($cap, '/');
    return preg_replace('/(?:'.$quoted.')+$/u', '', $value).$cap;
}

/**
 * Converts degrees to radians
 *
 * @param float $n Degrees
 *
 * @return float Radians
 */
function toRad($n)
{
    return ($n * pi() / 180);
}

/**
 * Gets the tile x y zoom coordinates from lat lon and zoom values
 *
 * @param float $lat  The latitude
 * @param float $lon  The longitude
 * @param int   $zoom Zoom level
 *
 * @return array [x, y, zoom]
 */
function getTileCoordsFromLatLng($lat, $lon, $zoom)
{
    $xtile = intval(floor(($lon + 180) / 360 * (1 << $zoom)));
    $ytile = intval(floor((1 - log(tan(toRad($lat)) + 1 / cos(toRad($lat))) / pi()) / 2 * (1 << $zoom)));
    return [
        $xtile,
        $ytile,
        $zoom,
    ];
}

$tileCoords = getTileCoordsFromLatLng($location['latitude'], $location['longitude'], $location['zoom']);

$z    = $tileCoords[2];
$ymin = ($tileCoords[0] - $location['tilesy']);
$ymax = ($tileCoords[0] + $location['tilesy']);
$xmin = ($tileCoords[1] - $location['tilesx']);
$xmax = ($tileCoords[1] + $location['tilesx']);

$tx = (abs($xmax - $xmin) + 1);
$ty = (abs($ymax - $ymin) + 1);
$h  = ($tx * $tileWidth);
$w  = ($ty * $tileWidth);

$cli->green('Downloading and caching tiles for:');
$cli->out(sprintf('    x = <red>%d</red> to <blue>%d</blue> (<green>%d</green> width)', $xmin, $xmax, $tx));
$cli->out(sprintf('    y = <red>%d</red> to <blue>%d</blue> (<green>%d</green> height)', $ymin, $ymax, $ty));
$cli->out(sprintf('    z = <blue>%d</blue>', $z));
$cli->green('Center:');
$cli->out(sprintf('  lat = <blue>%s</blue>', $location['latitude']));
$cli->out(sprintf('  lon = <blue>%s</blue>', $location['longitude']));


$cli->out(sprintf('Starting %d x %d [%d x %dpx] image download && generation', $tx, $ty, $w, $h));
$allCached = true;
$previewed = true;

$totalTiles = ($tx * $ty);
$progress   = $cli->progress()->total($totalTiles);

for ($x = $xmin; $x <= $xmax; $x++) {
    $progress->advance(0, sprintf("Downloading col %d/%d", ($x - $xmin), $tx));
    $promises = [];
    for ($y = $ymin; $y <= $ymax; $y++) {
        $turl    = sprintf($url, $z, $y, $x, $apiKey);
        if ($cmd['verbose']) {
            $cli->out(sprintf('Downloading %s', $turl));
        }
        $cachefn = $cacheDir . DIRECTORY_SEPARATOR  . sprintf('%d-%d-%d.png', $z, $y, $x);
        if (!file_exists($cachefn)) {
            $promises[$cachefn] = $client->getAsync($turl);
        } else {
            $progress->advance(1);
        }
    }
    $responses = Promise\Utils::unwrap($promises);
    foreach ($responses as $fn => $response) {
        $progress->advance(1);
        file_put_contents($fn, $response->getBody()->getContents());
    }
}
$progress->current($totalTiles, 'Download complete, now to combine the images');

// Finally, we have a Python script for post-processing the images.
$p = $cmd['location']??'render-direct';
$nfn  = sprintf($outputDir . '%s-%d-%d+%d-%d+%d-p.png', $p, $z, $xmin, $tx, $ymin, $ty);
$cmd1 = sprintf($cmd['python'] . ' combineImages.py %d %d %d %d %d %d "%s"', $xmin, $tx, $ymin, $ty, $z, $tileWidth, $nfn);
$cmd2 = sprintf($cmd['python'] . ' convertTo16BitCpu.py "%s"', $nfn);

if ($cmd['executepython']) {
    $cli->out(sprintf('Executing %s', $cmd1));
    $process = new Process([$cmd['python'], 'combineImages.py', $xmin, $tx, $ymin, $ty, $z, $tileWidth, $nfn]);
    $process->run(
        function ($type, $buffer) use ($cli) {
            if (Process::ERR === $type) {
                $cli->error($buffer);
            } else {
                $cli->out($buffer);
            }
        }
    );

    $cli->out(sprintf('Executing %s', $cmd2));
    $process = new Process([$cmd['python'], 'convertTo16BitCpu.py', $nfn]);
    $process->run(
        function ($type, $buffer) use ($cli) {
            if (Process::ERR === $type) {
                $cli->error($buffer);
            } else {
                $cli->out($buffer);
            }
        }
    );
} else {
    $cli->out('Now run the following commands:');
    $cli->out($cmd1);
    $cli->out($cmd2);
}//end if