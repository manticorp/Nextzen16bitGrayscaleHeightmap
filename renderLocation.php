<?php

/**
 * CLI tool that downloads a series of tiles from the terrarium API
 *
 * @author  Harry Mustoe-Playfair <h@hmp.is.it>
 * @license MIT https://opensource.org/licenses/MIT
 */

include 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use League\CLImate\CLImate;
use Commando\Command;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

$client = new Client();
$cli    = new CLImate();
$cmd    = new Command();

$cmd->option('l')
    ->aka('latitude')
    ->default(36.6271)
    ->describedAs('The latitude for the center point for your export');

$cmd->option('k')
    ->aka('longitude')
    ->default(138.20453)
    ->describedAs('The longitude for the center point for your export');

$cmd->option('z')
    ->aka('zoom')
    ->default(15)
    ->describedAs('The zoom level of your export');

$cmd->option('x')
    ->aka('tilesx')
    ->default(33)
    ->describedAs('The number of tiles to output in the +/- x direction. This does not include the center tile, e.g. a value of 1 will be 3 tiles in width.');

$cmd->option('y')
    ->aka('tilesy')
    ->default(33)
    ->describedAs('The number of tiles to output in the +/- y direction. This does not include the center tile, e.g. a value of 1 will be 3 tiles in height.');

$cmd->option('apikey')
    ->aka('a')
    ->required()
    ->describedAs('Terrarium api key');

$cmd->option('c')
    ->aka('cacheDir')
    ->default('cache')
    ->describedAs('The cache directory to use');

$cmd->option('t')
    ->aka('tiledimension')
    ->default(256)
    ->describedAs('The width/height of the outputted tiles. Will be 256 in most cases I believe.');

$cmd->option('e')
    ->aka('executepython')
    ->boolean()
    ->describedAs('Whether to execute python scripts, by default just shows the commands');

$apiKey   = $cmd['apikey'];
$url      = "https://s3.amazonaws.com/elevation-tiles-prod/terrarium/%d/%d/%d.png?api_key=%s";
$cacheDir = 'cache';

$tileWidth = $cmd['tiledimension'];

// The location in the form [latitude, longitude, zoom].
$location = [
    $cmd['latitude'],
    $cmd['longitude'],
    $cmd['zoom'],
];

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
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

$tileCoords = getTileCoordsFromLatLng($location[0], $location[1], $location[2]);

$z    = $tileCoords[2];
$ymin = ($tileCoords[0] - $cmd['y']);
$ymax = ($tileCoords[0] + $cmd['y']);
$xmin = ($tileCoords[1] - $cmd['x']);
$xmax = ($tileCoords[1] + $cmd['x']);

$tx = (abs($xmax - $xmin) + 1);
$ty = (abs($ymax - $ymin) + 1);
$h  = ($tx * $tileWidth);
$w  = ($ty * $tileWidth);

$cli->green('Downloading and caching tiles for:');
$cli->out(sprintf('    x = <red>%d</red> to <blue>%d</blue> (<green>%d</green> width)', $xmin, $xmax, $tx));
$cli->out(sprintf('    y = <red>%d</red> to <blue>%d</blue> (<green>%d</green> height)', $ymin, $ymax, $ty));
$cli->out(sprintf('    z = <blue>%d</blue>', $z));
$cli->green('Center:');
$cli->out(sprintf('  lat = <blue>%s</blue>', $cmd['latitude']));
$cli->out(sprintf('  lon = <blue>%s</blue>', $cmd['longitude']));


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
$nfn  = sprintf('render-direct-%d-%d+%d-%d+%d-p.png', $z, $xmin, $tx, $ymin, $ty);
$cmd1 = sprintf('python3 combineImages.py %d %d %d %d %d %d "%s"', $xmin, $tx, $ymin, $ty, $z, $tileWidth, $nfn);
$cmd2 = sprintf('python3 convertTo16BitCpu.py "%s"', $nfn);

if ($cmd['executepython']) {
    $cli->out(sprintf('Executing %s', $cmd1));
    $process = new Process(['python3', 'combineImages.py', $xmin, $tx, $ymin, $ty, $z, $tileWidth, $nfn]);
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
    $process = new Process(['python3', 'convertTo16BitCpu.py', $nfn]);
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