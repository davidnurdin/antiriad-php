<?php

namespace App\Net;

/**
 * Online messages (one type byte + payload, JumPHP\Net\MessageCodec). The host (left paddle) is the authority:
 * it moves the ball, counts the points and sends SNAPSHOT 20 times a second; the guest sends its paddle (STATE)
 * 30 times a second. Unreliable for the frequent ones (a lost one is replaced by the next), reliable for EVENT.
 */
final class PongProtocol
{
    /** guest -> host: paddle top y. pack('g') */
    public const STATE = 1;
    /** host -> guest: ball x, y, vx, vy, left paddle y, right paddle y, score left, score right. pack('ggggggCC') */
    public const SNAPSHOT = 2;
    /** host -> guest: {"sfx": "hit"} or {"over": "left"|"right"}. JSON, reliable */
    public const EVENT = 3;

    public const SNAPSHOT_FORMAT = 'gbx/gby/gbvx/gbvy/gleft/gright/CscoreLeft/CscoreRight';
}
