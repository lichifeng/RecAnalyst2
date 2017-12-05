<?php

namespace RecAnalyst\Model;

use RecAnalyst\Model\Player;
use RecAnalyst\Utils;

/**
 * The ChatMessage class represents a single chat message sent before or during
 * the game.
 */
class ChatMessage
{
    /**
     * Sent time in milliseconds since the start of the game.
     *
     * @var int
     */
    public $time;

    /**
     * Player who sent this message.
     *
     * @var $name
     */
    public $player_name;

    /**
     * Message text.
     *
     * @var string
     */
    public $msg;

    /**
     * Group at which this chat is directed (<Team>, <Enemy>, <All>), if any.
     *
     * @var string
     */
    public $group;

    /**
     * Class constructor.
     *
     * @param int  $time  When this message was sent, in milliseconds since the
     *     start of the game.
     * @param string  $name  Player that sent the message.
     * @param string  $msg  Message content.
     * @param string  $group  Group this message was directed to.
     * @return void
     */
    public function __construct($time = 0, $name = '', $msg = '', $group = '')
    {
        $this->time = $time;
        $this->player_name = Utils::stringToUTF8($name);
        $this->msg = Utils::stringToUTF8($msg);
        $this->group = Utils::stringToUTF8($group);
    }

    /**
     * Return an array representation of a chat message
     *
     * @return array
     */
    public function toArray() {
        return [
            Utils::formatGameTime($this->time),
            $this->player_name,
            $this->msg,
            $this->group
        ];
    }

    /**
     * Helper method to create a chat message from a chat string more easily.
     *
     * Messages actually have the player name and sometimes a group specifier
     * (<Team>, <Enemy>, etc) included in their message body which is lame.
     * Sometimes players that don't end up in the player info blocks of the
     * recorded games sent messages anyway (particularly pre-game chat by people
     * who joined the multiplayer lobby and then left) so we deal with that too.
     *
     * @param int  $time  Time at which this message was sent in milliseconds
     *    since the start of the game.
     * @param string  $chat  Message contents.
     * @return ChatMessage
     */
    public static function create($time, $chat)
    {
        $group = '';
        // This is directed someplace (@All, @Team, @Enemy, etc.)
        // Voobly adds @Rating messages too, which we might wish to parse into
        // the player objects later as a `->rating` property.
        if ($chat[0] === '<') {
            // Standard directed chat messages have a format like:
            //   <All>PlayerName: message
            // Voobly rating messages however:
            //   <Rating> PlayerName: message
            // ...adds a space character before the name, so we deal with it
            // separately.
            if (substr($chat, 0, 9) === '<Rating> ') {
                $group = '<Rating>';
                $chat = substr($chat, 9);
            } else {
                $end = strpos($chat, '>');
                $group = substr($chat, 0, $end + 1);
                $chat = substr($chat, $end + 1);
            }
        }

        $name = substr($chat, 0, strpos($chat, ':'));
        $name = ltrim($name);
        // Cut the player name out of the message contents.
        $chat = ltrim(substr($chat, strlen($name) + 2));
        return new self($time, $name, $chat, $group);
    }
}
