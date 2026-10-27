<?php

namespace App\Scene;

use App\Ai\CpuPlayer;
use App\Entity\Ball;
use App\Entity\Paddle;
use App\Net\PongProtocol;
use JumPHP\Audio\Synth;
use JumPHP\Loop\FixedStep;
use JumPHP\Net\MessageCodec;
use JumPHP\Net\NetSession;
use JumPHP\Net\SnapshotBuffer;
use JumPHP\Physics\Aabb;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Lang;

/**
 * A match. Modes (enter params):
 *   ['mode' => 'cpu']    player (left) against the computer
 *   ['mode' => 'local']  two players on one keyboard
 *   ['mode' => 'demo']   computer against computer (title background, headless test)
 *   ['mode' => 'online', 'net' => NetSession, 'host' => bool]  from the LobbyScene: the host plays left
 *       and runs the ball, the guest plays right and shows what the host sends.
 */
final class PlayScene extends AbstractScene
{
    public const TOP = 14;
    public const BOTTOM = 176;
    private const MARGIN = 10;
    private const SERVE_DELAY = 1.0;
    private const SNAPSHOT_RATE = 1 / 20;
    private const STATE_RATE = 1 / 30;

    private string $mode = 'cpu';
    private Paddle $left;
    private Paddle $right;
    private Ball $ball;
    /** @var array<string, CpuPlayer> side => computer */
    private array $cpus = [];
    private array $score = [0, 0];
    private int $winScore = 7;
    private float $serveTimer = 0.0;
    private int $serveDir = 1;
    private bool $over = false;
    private FixedStep $step;
    private array $params = [];

    private ?NetSession $net = null;
    private bool $host = true;
    private ?SnapshotBuffer $remote = null;
    private float $sendTimer = 0.0;
    private float $pingTimer = 0.0;
    private ?string $message = null;

    public function enter(array $params = []): void
    {
        $this->params = $params;
        $this->mode = $params['mode'] ?? 'cpu';
        $options = $this->engine->options;
        $this->winScore = $this->mode === 'demo' ? 3 : (int)$options->get('points', 7);
        $w = $this->width();
        $mid = (self::TOP + self::BOTTOM) / 2;
        $this->left = new Paddle(self::MARGIN, $mid - Paddle::HEIGHT / 2, self::TOP, self::BOTTOM);
        $this->right = new Paddle($w - self::MARGIN - Paddle::WIDTH, $mid - Paddle::HEIGHT / 2, self::TOP, self::BOTTOM);
        $this->ball = new Ball($w / 2, $mid);
        $this->score = [0, 0];
        $this->over = false;
        $this->message = null;
        $this->step = new FixedStep(120);   // the ball moves up to 380 px/s: small steps, no tunnelling
        $this->serveDir = random_int(0, 1) ? 1 : -1;
        $this->serveTimer = self::SERVE_DELAY;

        $level = (string)$options->get('cpu', 'normal');
        $this->cpus = match ($this->mode) {
            'cpu' => ['right' => new CpuPlayer($this->right, 1, self::TOP, self::BOTTOM, $level)],
            'demo' => ['left' => new CpuPlayer($this->left, -1, self::TOP, self::BOTTOM, 'hard'),
                'right' => new CpuPlayer($this->right, 1, self::TOP, self::BOTTOM, 'normal')],
            default => [],
        };

        $this->net = null;
        if ($this->mode === 'online') {
            $this->startOnline($params['net'], (bool)$params['host']);
        }

        $sound = $this->sound();
        if (!$sound->hasSfx('hit')) {
            $sound->synth('hit', Synth::tone(480, 0.05));
            $sound->synth('wall', Synth::tone(240, 0.05));
            $sound->synth('score', Synth::sweep(600, 150, 0.35));
            $sound->synth('win', Synth::sequence([[523, 0.1], [659, 0.1], [784, 0.1], [1047, 0.3]]));
        }
    }

    private function startOnline(NetSession $net, bool $host): void
    {
        $this->net = $net;
        $this->host = $host;
        $this->remote = new SnapshotBuffer(0.1);
        $net->removeAllListeners();
        $net->on('message', fn (int $from, int $type, string $payload) => $this->onMessage($type, $payload));
        $net->on('peerLeave', fn () => $this->opponentLeft());
        $net->on('close', fn () => $this->opponentLeft());
    }

    public function update(float $dt): void
    {
        if ($this->message !== null) {
            if ($this->actions()->isPressed('confirm') || $this->actions()->isPressed('back')) {
                $this->leave();
            }
            return;
        }
        if ($this->actions()->isPressed('pause') && !$this->over) {
            if ($this->mode === 'online') {
                $this->leave();             // a match cannot wait: Escape leaves it
            } elseif ($this->mode === 'demo') {
                $this->scenes()->switchTo(TitleScene::class);
            } else {
                $this->scenes()->push(PauseScene::class);
            }
            return;
        }

        $this->controls($dt);
        if ($this->mode !== 'online' || $this->host) {
            $this->step->run($dt, fn (float $h) => $this->simulate($h));
        } else {
            $this->followHost();
        }
        if ($this->net !== null) {
            $this->sendNetwork($dt);
        }
    }

    /** Who moves which paddle. */
    private function controls(float $dt): void
    {
        $a = $this->actions();
        foreach ($this->cpus as $cpu) {
            $cpu->update($this->ball, $dt);
        }
        switch ($this->mode) {
            case 'local':
                $this->left->move($a->axis('p1_up', 'p1_down'), $dt);
                $this->right->move($a->axis('p2_up', 'p2_down'), $dt);
                break;
            case 'cpu':
                $this->solo($this->left, $dt);
                break;
            case 'online':
                $this->solo($this->host ? $this->left : $this->right, $dt);
                break;
        }
    }

    /** One player: keys, or follows the finger / the mouse button. */
    private function solo(Paddle $paddle, float $dt): void
    {
        $input = $this->engine->input;
        if ($input->getTouch()->active) {
            $paddle->moveTowards($input->getTouch()->y * $this->height(), $dt);
        } elseif ($input->getMouse()->isHeld(1)) {
            $paddle->moveTowards($input->getMouse()->y, $dt);
        } else {
            $paddle->move($this->actions()->axis('solo_up', 'solo_down'), $dt);
        }
    }

    /** One physics step (host / offline). */
    private function simulate(float $h): void
    {
        if ($this->over) {
            return;
        }
        if ($this->serveTimer > 0) {
            $this->serveTimer -= $h;
            if ($this->serveTimer <= 0) {
                $this->ball->serve($this->width() / 2, (self::TOP + self::BOTTOM) / 2, $this->serveDir);
            }
            return;
        }
        $ball = $this->ball;
        $ball->update($h);
        [, $vy] = $ball->getVelocity();
        if (($ball->getFloatY() < self::TOP && $vy < 0) || ($ball->getFloatY() + Ball::SIZE > self::BOTTOM && $vy > 0)) {
            $ball->bounceY();
            $this->sfx('wall');
        }
        foreach ([[$this->left, 1], [$this->right, -1]] as [$paddle, $dir]) {
            [$vx] = $ball->getVelocity();
            if (($vx < 0) === ($dir > 0) && Aabb::intersects($ball->getBoundingBox(), $paddle->getBoundingBox())) {
                $offset = ($ball->centerY() - $paddle->centerY()) / ($paddle->getHeight() / 2);
                $ball->setX($dir > 0 ? $paddle->getFloatX() + Paddle::WIDTH : $paddle->getFloatX() - Ball::SIZE);
                $ball->bounceFromPaddle($offset, $dir);
                $this->sfx('hit');
            }
        }
        if ($ball->getFloatX() + Ball::SIZE < 0) {
            $this->point(1);
        } elseif ($ball->getFloatX() > $this->width()) {
            $this->point(0);
        }
    }

    /** $side 0 = left scores, 1 = right scores. */
    private function point(int $side): void
    {
        $this->score[$side]++;
        $this->serveDir = $side === 0 ? 1 : -1;   // the next ball goes to the one who missed
        $this->serveTimer = self::SERVE_DELAY;
        $this->ball->setVelocity(0, 0);
        $this->ball->setXY($this->width() / 2 - Ball::SIZE / 2, (self::TOP + self::BOTTOM) / 2);
        if ($this->score[$side] >= $this->winScore) {
            $this->gameOver($side === 0 ? 'left' : 'right');
            $this->broadcast(['over' => $side === 0 ? 'left' : 'right']);
        } else {
            $this->sfx('score');
        }
    }

    private function gameOver(string $winner): void
    {
        $this->over = true;
        $this->sound()->playSfx('win');
        $text = match ($this->mode) {
            'cpu' => $winner === 'left' ? Lang::t('YOU WIN!') : Lang::t('YOU LOSE'),
            'online' => ($winner === 'left') === $this->host ? Lang::t('YOU WIN!') : Lang::t('YOU LOSE'),
            default => Lang::t('{player} WINS!', ['player' => Lang::t($winner === 'left' ? 'PLAYER 1' : 'PLAYER 2')]),
        };
        if ($this->mode === 'demo') {
            // the computer plays forever: next match
            $this->engine->gameLoop->addTimer(2.0, fn () => $this->scenes()->current() === $this && $this->enter($this->params));
            return;
        }
        $this->scenes()->push(GameOverScene::class, ['text' => $text, 'again' => $this->mode !== 'online' ? $this->params : null]);
    }

    private function sfx(string $name): void
    {
        $this->sound()->playSfx($name);
        $this->broadcast(['sfx' => $name]);
    }

    // --- network ---------------------------------------------------------------------------------

    private function broadcast(array $event): void
    {
        if ($this->net !== null && $this->host) {
            $this->net->sendJson(PongProtocol::EVENT, $event);
        }
    }

    private function sendNetwork(float $dt): void
    {
        $this->pingTimer -= $dt;
        if ($this->pingTimer <= 0) {
            $this->pingTimer = 2.0;
            $this->net->ping();
        }
        $this->sendTimer -= $dt;
        if ($this->sendTimer > 0) {
            return;
        }
        if ($this->host) {
            $this->sendTimer = self::SNAPSHOT_RATE;
            [$vx, $vy] = $this->ball->getVelocity();
            $this->net->send(PongProtocol::SNAPSHOT, pack('ggggggCC', $this->ball->getFloatX(), $this->ball->getFloatY(), $vx, $vy,
                $this->left->getFloatY(), $this->right->getFloatY(), $this->score[0], $this->score[1]), reliable: false);
        } else {
            $this->sendTimer = self::STATE_RATE;
            $this->net->send(PongProtocol::STATE, pack('g', $this->right->getFloatY()), reliable: false);
        }
    }

    private function onMessage(int $type, string $payload): void
    {
        if ($type === PongProtocol::STATE && $this->host) {
            $y = MessageCodec::unpack('gy', $payload)['y'] ?? null;
            if ($y !== null) {
                $this->right->setPosition($y);   // clamped to the court: a cheater cannot leave it
            }
        } elseif ($type === PongProtocol::SNAPSHOT && !$this->host) {
            $s = MessageCodec::unpack(PongProtocol::SNAPSHOT_FORMAT, $payload);
            if ($s !== []) {
                $this->remote->push($this->engine->time(), $s);
            }
        } elseif ($type === PongProtocol::EVENT && !$this->host) {
            $event = MessageCodec::unjson($payload);
            if (isset($event['sfx'])) {
                $this->sound()->playSfx((string)$event['sfx']);
            }
            if (isset($event['over'])) {
                $this->gameOver((string)$event['over']);
            }
        }
    }

    /** Guest: the ball and the host's paddle as the host sees them, 100 ms ago (smooth). */
    private function followHost(): void
    {
        $s = $this->remote->sample($this->engine->time());
        if ($s === null) {
            return;
        }
        $this->ball->setXY($s['bx'], $s['by']);
        $this->ball->setVelocity($s['bvx'], $s['bvy']);
        $this->left->setPosition($s['left']);
        $latest = $this->remote->latest();
        $this->score = [(int)$latest['scoreLeft'], (int)$latest['scoreRight']];
    }

    private function opponentLeft(): void
    {
        if ($this->scenes()->current() !== $this && !$this->over) {
            return;
        }
        $this->message = Lang::t('OPPONENT LEFT');
    }

    private function leave(): void
    {
        $this->net?->close();
        $this->net = null;
        $this->scenes()->switchTo(TitleScene::class);
    }

    public function exit(): void
    {
        if ($this->net !== null) {
            $this->net->removeAllListeners();
        }
    }

    // --- drawing ---------------------------------------------------------------------------------

    public function render(Renderer $r): void
    {
        $w = $this->width();
        $font = $this->font();
        $r->clear([0, 0, 0]);
        $r->fillRect(0, self::TOP - 2, $w, 2, [90, 90, 90]);
        $r->fillRect(0, self::BOTTOM, $w, 2, [90, 90, 90]);
        for ($y = self::TOP + 2; $y < self::BOTTOM; $y += 8) {
            $r->fillRect($w / 2 - 1, $y, 2, 4, [70, 70, 70]);
        }
        $font->draw($r, (string)$this->score[0], $w / 2 - 24, 20, 3, [180, 180, 180], 'center');
        $font->draw($r, (string)$this->score[1], $w / 2 + 24, 20, 3, [180, 180, 180], 'center');

        $this->left->render($r);
        $this->right->render($r);
        if ($this->serveTimer <= 0 || $this->mode === 'online') {
            $this->ball->render($r);
        }

        $labels = match ($this->mode) {
            'cpu' => [Lang::t('YOU'), Lang::t('COMPUTER')],
            'online' => $this->host ? [Lang::t('YOU'), 'P2'] : ['P1', Lang::t('YOU')],
            'demo' => ['CPU', 'CPU'],
            default => ['W / S', 'UP / DOWN'],
        };
        $font->draw($r, $labels[0], 4, 3, 1, [110, 110, 110]);
        $font->draw($r, $labels[1], $w - 4, 3, 1, [110, 110, 110], 'right');
        if ($this->net !== null && ($ping = $this->net->lastPing()) !== null) {
            $font->draw($r, $ping . 'MS', $w / 2, 3, 1, [80, 80, 80], 'center');
        }
        if ($this->message !== null) {
            \JumPHP\Ui\Hud::message($r, $font, $this->message, $w / 2, $this->height() / 2);
        }
    }
}
