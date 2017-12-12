<?php

namespace RecAnalyst;

use RecAnalyst\BasicTranslator;
use RecAnalyst\StreamExtractor;
use RecAnalyst\Analyzers\Analyzer;
use RecAnalyst\Processors\MapImage;
use RecAnalyst\Processors\Achievements;
use RecAnalyst\ResourcePacks\ResourcePack;

/**
 * Represents a recorded game file.
 */
class RecordedGame
{
    /**
     * Completed analyses.
     *
     * @var array
     */
    protected $analyses = [];

    /**
     * File handle to the recorded game file.
     *
     * @var resource
     */
    private $fp;

    /**
     * Current resource pack.
     *
     * @var \RecAnalyst\ResourcePacks\ResourcePack
     */
    private $resourcePack = null;

    /**
     * @var bool
     */
    private $isLaravel = false;

    /**
     * RecAnalyst options.
     */
    private $options = [];

    /**
     * Record file
     */
    public $file;



    /**
     * Create a recorded game analyser.
     *
     * @param  resource|string|\SplFileInfo| Illuminate\Http\Request  $file_source  Path or handle to the recorded game file.
     * @param  array  $options
     * @return void
     */
    public function __construct($file_source = null, array $options = [])
    {
        $this->retrieveFile($file_source);

        $this->openFile();

        // Remember if we're in a Laravel environment.
        $this->isLaravel = function_exists('app') && is_a(app(), 'Illuminate\Foundation\Application');

        // Parse options and defaults.
        $this->options = array_merge([
            'translator' => null,
        ], $options);

        if (!$this->options['translator']) {
            if ($this->isLaravel) {
                $this->options['translator'] = app('translator');
            } else {
                $this->options['translator'] = new BasicTranslator();
            }
        }

        // Set default resource pack. The VersionAnalyzer could be used in the
        // future to detect which resource pack to use, should support for SWGB
        // or other games be added.
        $this->resourcePack = new ResourcePacks\AgeOfEmpires();

        // Initialize the header/body extractor.
        $this->streams = new StreamExtractor($this->fp, $options);
    }

    /**
     * Take game file from file source
     * If a Laravel request is passed, only the first file in a request will be taken out
     *
     * @param $file_source
     */
    private function retrieveFile($file_source) {
        // Set the file name and file pointer/handle/resource. (pick your
        // favourite name…!)
        if (is_resource($file_source)) {
            $this->fp = $file_source;
            $this->file = '';
        } else if (is_object($file_source) && is_a($file_source, 'SplFileInfo')) {
            $this->file = $file_source->getRealPath();
        } else if (is_object($file_source) && is_a($file_source, 'Illuminate\Http\Request')) {
            // Take the first file found in a Laravel request
            $file_input = $file_source->file();
            $file_input = reset($file_input);
            $this->file = is_array($file_input) ? $file_input[0] : $file_input;
        } else {
            $this->file = $file_source;
        }
    }
    
    /**
     * Create a file handle for the recorded game file.
     *
     * @return void
     */
    private function openFile()
    {
        $this->fp = $this->fp ? $this->fp : @fopen($this->file, 'r');
    }

    /**
     * Get the current resource pack.
     *
     * @return \RecAnalyst\ResourcePacks\ResourcePack
     */
    public function getResourcePack()
    {
        return $this->resourcePack;
    }

    /**
     * Run an analysis on the current game.
     *
     * @param  \RecAnalyst\Analyzers\Analyzer  $analyzer
     * @return mixed
     */
    public function runAnalyzer(Analyzer $analyzer)
    {
        return $analyzer->analyze($this);
    }

    /**
     * Get an analysis result for a specific analyzer, running it if necessary.
     *
     * @param string  $analyzerName  Fully qualified name of the analyzer class.
     * @param mixed  $arg  Optional argument to the analyzer.
     * @param int  $startAt  Position to start at.
     * @return mixed
     */
    public function getAnalysis($analyzerName, $arg = null, $startAt = 0)
    {
        $key = $analyzerName . ':' . $startAt;
        if (!array_key_exists($key, $this->analyses)) {
            $analyzer = new $analyzerName($arg);
            $analyzer->position = $startAt;
            $result = new \StdClass;
            $result->analysis = $this->runAnalyzer($analyzer);
            $result->position = $analyzer->position;
            $this->analyses[$key] = $result;
        }
        return $this->analyses[$key];
    }

    /**
     * Return the raw decompressed header contents.
     *
     * @return string
     */
    public function getHeaderContents()
    {
        return $this->streams->getHeader();
    }

    /**
     * Return the raw body contents.
     *
     * @return string
     */
    public function getBodyContents()
    {
        return $this->streams->getBody();
    }

    /**
     * Get the game version.
     *
     * @return \StdClass
     */
    public function version()
    {
        return $this->getAnalysis(Analyzers\VersionAnalyzer::class)->analysis;
    }

    /**
     * Get the result of analysis of the recorded game header.
     *
     * @return \StdClass
     */
    public function header()
    {
        return $this->getAnalysis(Analyzers\HeaderAnalyzer::class)->analysis;
    }

    /**
     * Get the game settings used to play this recorded game.
     *
     * @return \RecAnalyst\Model\GameSettings
     */
    public function gameSettings()
    {
        return $this->header()->gameSettings;
    }

    /**
     * Get the victory settings for this game.
     *
     * @return \RecAnalyst\Model\VictorySettings
     */
    public function victorySettings()
    {
        return $this->header()->victory;
    }

    /**
     * Get the result of analysis of the recorded game body.
     *
     * @return \StdClass
     */
    public function body()
    {
        return $this->getAnalysis(Analyzers\BodyAnalyzer::class)->analysis;
    }

    /**
     * Render a map image.
     *
     * @see \RecAnalyst\Processors\MapImage
     * @param array  $options  Rendering options.
     * @return \Intervention\Image Rendered image.
     */
    public function mapImage(array $options = [])
    {
        $proc = new MapImage($this, $options);
        return $proc->run();
    }

    /**
     * Get the teams that played in this recorded game.
     *
     * @return \RecAnalyst\Model\Team[] Teams.
     */
    public function teams()
    {
        return $this->header()->teams;
    }

    /**
     * Get the players that played in this recorded game.
     *
     * Excludes spectating players in HD Edition games.
     *
     * @return \RecAnalyst\Model\Player[] Players.
     */
    public function players()
    {
        if (isset($this->players)) {
            return $this->players;
        }
        $this->players =  array_filter($this->header()->players, function ($player) {
            return !$player->isSpectator();
        });
        return $this->players;
    }

    /**
     * Get the players that spectated this recorded game. Spectating players are
     * only saved in the recorded games played in HD Edition.
     *
     * @return \RecAnalyst\Model\Player[] Spectators.
     */
    public function spectators()
    {
        return array_filter($this->header()->players, function ($player) {
            return $player->isSpectator();
        });
    }

    /**
     * Get the POV player. This is the player that recorded this recorded game
     * file.
     *
     * @return \RecAnalyst\Model\Player
     */
    public function pov()
    {
        foreach ($this->header()->players as $player) {
            if ($player->owner) {
                return $player;
            }
        }
    }

    /**
     * Get a player by their index.
     *
     * @param int  $id  Player index.
     * @return \RecAnalyst\Model\Player|null
     */
    public function getPlayer($id)
    {
        foreach ($this->header()->players as $player) {
            if ($player->index === $id) {
                return $player;
            }
        }
    }

    /**
     * Get the player achievements.
     *
     * return \StdClass[] Achievements for each player.
     */
    public function achievements(array $options = [])
    {
        $proc = new Achievements($this, $options);
        return $proc->run();
    }

    /**
     * Generate research table
     *
     * @return array
     */
    public function researchTable() {
        $researches = [];
        foreach ($this->players() as $player) {
            $researches[$player->index] = [];
        }
        $researchesByMinute = [];
        foreach ($this->players() as $player) {
            foreach ($player->researches() as $research) {
                $minute = floor($research->time / 1000 / 60);
                $researchesByMinute[$minute][$player->index][] = [$research->id, $research->name()];
            }
        }
        foreach ($researchesByMinute as $minute => $researchesByPlayer) {
            foreach ($this->players() as $player) {
                if ($minute <= floor($player->feudalTime / 1000 / 60) || floor($player->feudalTime / 1000 / 60) == 0) {
                    $bg_id = 0;
                } elseif ($minute <= floor($player->castleTime / 1000 / 60) || floor($player->castleTime / 1000 / 60) == 0) {
                    $bg_id = 1;
                } elseif ($minute <= floor($player->imperialTime / 1000 / 60) || floor($player->imperialTime / 1000 / 60) == 0) {
                    $bg_id = 2;
                } else {
                    $bg_id = 3;
                }
                $researches[$player->index][$minute][0] = $bg_id;
                $researches[$player->index][$minute][1] = $researchesByPlayer[$player->index] ?? [];
            }
        }
        foreach ($researches as &$timeline) {
            ksort($timeline, SORT_NUMERIC);
        }
        return $researches;
    }

    /**
     * Get a translate key for use with Symfony or Laravel Translations.
     *
     * @return string A translation key.
     */
    private function getTranslateKey($args)
    {
        // Game version names are in their own file, not in with resource packs.
        if ($args[0] === 'game_versions') {
            $key = implode('.', $args);
        } else {
            $pack = get_class($this->resourcePack);
            $key = $pack::NAME . '.' . implode('.', $args);
        }
        if ($this->isLaravel) {
            return 'recanalyst::' . $key;
        }
        return $key;
    }

    /**
     *
     */
    public function getTranslator()
    {
        return $this->options['translator'];
    }

    /**
     * @return string
     */
    public function trans()
    {
        $key = $this->getTranslateKey(func_get_args());
        return $this->getTranslator()->trans($key);
    }
}
