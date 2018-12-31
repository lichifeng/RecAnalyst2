<?php

/**
 * A very barebones example. It outputs a map image to a PNG file and
 * outputs the players in the game to the command line.
 */

require __DIR__ . '/../vendor/autoload.php';

use RecAnalyst\RecordedGame;

$filename = $argv[1] ?? __DIR__ . '/../test/recs/hawkaoc-teamtest/rec.20181119-214848.mgz';
//$filename = $argv[1] ?? __DIR__ . '/../test/recs/WK-PLAYEROBJECT-BUG-20181205-213220.mgz';

// Read a recorded game from a file path.
$rec = new RecordedGame($filename);

$version = $rec->version();
echo 'Version: ' . $version->versionString . ' (' . $version->subVersion . ')' . "\n";

// Display players and their civilizations.
echo 'Players: ' . "\n";
foreach ($rec->output()->players as $player) {
    printf(" %s %s (%s) %s %s %s\n",
        $player->owner ? '>' : '*',
        $player->name,
        $player->civName(),
        'Index: ' . $player->index,
        'Team: ' . $player->team,
        'No.: ' . $player->number);
}

var_dump($rec->output()->teams);

// Render a map image. Map images are instances of the \Intervention\Image
// library, so you can easily manipulate them.
$rec->mapImage()
    ->resize(240, 120)
    ->save('minimap.png');

echo 'Minimap saved in minimap.png.' . "\n";
