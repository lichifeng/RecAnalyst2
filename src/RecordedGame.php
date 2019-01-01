<?php

namespace RecAnalyst;

use RecAnalyst\Analyzers\Analyzer;
use RecAnalyst\Model\Player;
use RecAnalyst\Processors\Achievements;
use RecAnalyst\Processors\MapImage;

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
    private $fp = false;

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
    public $file = null;
    public $fileMd5 = null;


    /**
     * Create a recorded game analyser.
     *
     * @param  resource|string|\SplFileInfo| Illuminate\Http\Request $file_source Path or handle to the recorded game file.
     * @param  array $options
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
    private function retrieveFile($file_source)
    {
        // Set the file name and file pointer/handle/resource. (pick your
        // favourite name…!)
        if (is_resource($file_source)) {
            $this->fp = $file_source;
            $this->file = null;
        } else if (is_object($file_source) && is_a($file_source, 'SplFileInfo')) {
            $this->file = $file_source->getRealPath();
        } else if (is_object($file_source) && is_a($file_source, 'Illuminate\Http\Request')) {
            // Take the first file found in a Laravel request
            $file_input = $file_source->file();
            $file_input = reset($file_input);
            $this->file = is_array($file_input) ? $file_input[0] : $file_input;
            $this->fileMd5 = md5_file($this->file);
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
     * @param  \RecAnalyst\Analyzers\Analyzer $analyzer
     * @return mixed
     */
    public function runAnalyzer(Analyzer $analyzer)
    {
        return $analyzer->analyze($this);
    }

    /**
     * Get an analysis result for a specific analyzer, running it if necessary.
     *
     * @param string $analyzerName Fully qualified name of the analyzer class.
     * @param mixed $arg Optional argument to the analyzer.
     * @param int $startAt Position to start at.
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
     * @param array $options Rendering options.
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
        $this->players = array_filter($this->header()->players, function (Player $player) {
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
        return array_filter($this->header()->players, function (Player $player) {
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

        throw new RecAnalystException('FOV information is not found.', 0x0008);
    }

    /**
     * Get a player by their index.
     *
     * @param int $id Player index.
     * @return \RecAnalyst\Model\Player|null
     */
    public function getPlayer($id)
    {
        foreach ($this->header()->players as $player) {
            if ($player->index === $id) {
                return $player;
            }
        }

        throw new RecAnalystException('Player of specified index is not found.', 0x0009);
    }

    /**
     * Get the player achievements.
     *
     * @param array $options
     * @return \StdClass[] Achievements for each player.
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
    public function researchTable()
    {
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
        foreach ($researches as &$timeLine) {
            ksort($timeLine, SORT_NUMERIC);
        }
        return $researches;
    }

    /**
     * Get a translate key for use with Symfony or Laravel Translations.
     *
     * @param $args
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

    /**
     * @return \stdClass
     */
    public function output()
    {
        $output = new \stdClass;
        // Record related data
        $output->isMultiplayers = !$this->header()->gameMode;
        $output->messages = $this->header()->messages;
        $output->includeAi = $this->header()->includeAi;
        $output->victoryMode = $this->victorySettings()->mode;
        $output->battleMode = $this->header()->gameInfo->getPlayersString();
        $output->mapName = $this->header()->gameSettings->mapName();
        $output->mapSize = $this->header()->gameSettings->mapSizeName();
        $output->gameType = $this->header()->gameSettings->gameTypeName();
        $output->difficulty = $this->header()->gameSettings->difficultyName();
        $output->gameSpeed = $this->header()->gameSettings->gameSpeed;
        $output->revealMap = $this->header()->gameSettings->revealMapName();
        $output->mapId = $this->header()->gameSettings->mapId;
        $output->popLimit = $this->header()->gameSettings->getPopLimit();
        $output->lockDiplomacy = $this->header()->gameSettings->getLockDiplomacy();
        $output->mapStyle = $this->header()->gameSettings->mapStyle();
        $output->startingAge = $this->pov()->startingAge();
        $output->duration = $this->body()->duration;
        $output->researchTable = $this->researchTable();

        $output->mapImage = $this->mapImage()->resize(360, 180);
        $output->version = $this->version()->name();
        $output->tributes = $this->body()->tributes;
        $output->units = $this->body()->units;
        $output->postGameData = isset($this->body()->postGameData->players) ? $this->body()->postGameData->players : null;
        $output->pov = $this->pov();
        $output->gameMd5 = $this->calculateGameMd5(
            $output->version,
            $output->battleMode,
            $output->mapId
        );

        // Player independent data
        $output->recMd5 = $this->fileMd5 === null ? null : $this->fileMd5;
        $output->ingameChat = $this->body()->chatMessages;
        $output->pregameChat = $this->header()->pregameChat;
        $output->players = $this->players();

        if ($output->postGameData !== null) {
            $output->teams = [];

            foreach ($output->players as $player) {
                if ($output->failedUP15 = $this->header()->playerInfo->failedUP15) {
                    $player->civId = $output->postGameData[$player->number-1]->civId;
                    $player->colorId = $output->postGameData[$player->number-1]->colorId - 1;
                    $player->feudalTime = $output->postGameData[$player->number-1]->techStats->feudalTime * 1000;
                    $player->castleTime = $output->postGameData[$player->number-1]->techStats->castleTime * 1000;
                    $player->imperialTime = $output->postGameData[$player->number-1]->techStats->imperialTime * 1000;
                    $player->resignTime = 0;
                    $output->duration = 0;
                    $output->gameSpeed = 0;
                }

                if ($output->postGameData[$player->number-1]->team == 1) {
                    $player->team = $output->postGameData[$player->number-1]->team = $player->index + 5;
                } else {
                    $player->team = $output->postGameData[$player->number-1]->team;
                }

                $winner = $output->postGameData[$player->number-1]->victory;
                $output->teams[$player->team]['is_winner'] = $winner ? true : false;
                $output->teams[$player->team]['players'][] = [$player->index, $player->number];
            }
        } else {
            $output->teams = $this->teamAndWinner();
        }

        return $output;
    }

    public function getBuildings($index)
    {
        return isset($this->body()->buildings[$index]) ? $this->body()->buildings[$index] : [];
    }

    protected function calculateGameMd5($version, $battleMode, $mapId)
    {
        $player_salt = [];
        foreach ($this->players() as $p) {
             $player_salt[] = $p->index . $p->name . $p->civId . $p->position()[0] . $p->position()[1];
        }
        sort($player_salt);
        $player_salt = implode($player_salt);
        $rec_salt =
            $version .
            $battleMode .
            $mapId .
            $player_salt;
        return md5($rec_salt);
    }

    protected function teamAndWinner() {
        $t = []; // percent of losers of every team
        $loser_cnt = [];
        $team_cnt = [];
        $max_ratio = 0.2;
        $max_index = [];
        $loser_found = false;

        foreach ($this->teams() as $team) {
            if($team->index() === 0) {
                foreach ($team->players() as $p) {
                    $new_index = $p->index + 5;
                    $t[$new_index]['players'][] = [$p->index, $p->number];
                    $team_cnt[$new_index] = 1;
                    if($p->resignTime > 0) {
                        $t[$new_index]['is_winner'] = isset($t[$new_index]['is_winner']) ? $t[$new_index]['is_winner'] : false;
                        $loser_cnt[$new_index] = $t[$new_index]['is_winner'] ? 0 : 1;
                    } else {
                        $t[$new_index]['is_winner'] = true;
                        $loser_cnt[$new_index] = 0;
                    }
                }
                continue;
            }

            $t[$team->index()]['is_winner'] = true;
            $is_loser = [];
            foreach ($team->players() as $p) {
                $t[$team->index()]['players'][] = [$p->index, $p->number];
                if ($p->resignTime > 0) {
                    $is_loser[$p->index] = (isset($is_loser[$p->index]) && $is_loser[$p->index] === false) ? false : true;
                } else {
                    $is_loser[$p->index] = false;
                }
            }
            $loser_cnt[$team->index()] = array_reduce($is_loser, function($losers, $p){
                return $losers += $p ? 1 : 0;
            }, 0);
            $team_cnt[$team->index()] = count($is_loser);
        }

        foreach ($loser_cnt as $i => $c) {
            $r = $c / $team_cnt[$i];
            if($r === 1) {
                $t[$i]['is_winner'] = false;
                $loser_found = true;
            }

            if($r < $max_ratio) continue;

            if($r > $max_ratio) {
                $max_ratio = $r;
                $max_index = [$i];
                continue;
            }

            if($r === $max_ratio) {
                $max_index[] = $i;
            }
        }

        if(!$loser_found) {
            try {
                if($this->pov()->resignTime === 0) {
                    $index = $this->pov()->team()->index() === 0 ? $this->pov()->index + 5 : $this->pov()->team()->index();
                    $loser_cnt[$index] += $loser_cnt[$index] > 0 ? 1 : 0;
                    $r = $loser_cnt[$index] / $team_cnt[$index];

                    if($r > $max_ratio) {
                        $max_index = [$index];
                    }
                }
            } catch (RecAnalystException $e) {
                // Pass
            }
            foreach ($max_index as $i) {
                $t[$i]['is_winner'] = false;
            }
        }
        return $t;
    }
}
