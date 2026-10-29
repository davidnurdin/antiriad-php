<?php

namespace Tests;

use App\Entity\Paddle;
use App\Scene\PlayScene;
use JumPHP\Testing\GameTestCase;

/** The paddles, played: keys held, frames run, the paddle looked at (bin/jumphp test pong; the jumphp-live pane shows it). */
final class PaddleTest extends GameTestCase
{
    public function testThePaddleGoesFromTheTopToTheBottom(): void
    {
        $this->game->start(PlayScene::class, ['mode' => 'local']);
        /** @var Paddle $paddle */
        $paddle = $this->game->peek('left');

        $this->game->hold('p1_up', 60);                 // up to the top
        self::assertEqualsWithDelta(PlayScene::TOP, $paddle->getBoundingBox()['y'], 1);

        $this->game->hold('p1_down', 90);               // then down to the bottom
        self::assertEqualsWithDelta(PlayScene::BOTTOM - Paddle::HEIGHT, $paddle->getBoundingBox()['y'], 1);
    }

    public function testThePaddleStaysInTheCourt(): void
    {
        $this->game->start(PlayScene::class, ['mode' => 'local']);
        /** @var Paddle $paddle */
        $paddle = $this->game->peek('left');

        $this->game->hold('p1_down', 180);              // three seconds against the bottom wall
        self::assertLessThanOrEqual(PlayScene::BOTTOM, $paddle->getBoundingBox()['y'] + Paddle::HEIGHT);
    }

    public function testTheBallIsServed(): void
    {
        $this->game->start(PlayScene::class, ['mode' => 'local']);
        $ball = $this->game->peek('ball');
        $x = $ball->centerX();

        $this->game->wait(2);                           // the serve, then the ball flies
        self::assertNotEqualsWithDelta($x, $ball->centerX(), 5);
    }
}
