<?php

namespace App\Scene;

use App\Work\Fractal;
use JumPHP\Async\Pool;
use JumPHP\Async\Tasks;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Time;

/**
 * "Hello world" of threads: a fractal computed row by row by a pool of worker threads (async runtime), while
 * the main thread keeps drawing at full speed (the spinning bar never stops). M switches to computing on the
 * main thread (a few ms per frame) to compare; SPACE renders the next spot.
 * Same code everywhere: TrueAsync threads (runtime async), Web Workers (browser), or between-frames work.
 */
final class FractalScene extends AbstractScene
{
    private const W = 192;
    private const H = 144;
    private const BACKENDS = ['threads' => 'TRUEASYNC THREADS', 'workers' => 'WEB WORKERS', 'frames' => 'NO THREADS'];
    private const ITER = 2500;   // ~0.5 to 2 s of work per picture on one core

    private ?Pool $pool = null;
    private bool $threads = true;
    private bool $demo = false;
    private int $spot = 0;
    private int $generation = 0;
    /** @var array<int, string> rows computed, not drawn into the texture yet */
    private array $fresh = [];
    private int $done = 0;
    private int $nextRow = 0;          // main-thread mode: next row to compute
    private float $started = 0.0;
    private float $lastTime = 0.0;     // seconds of the last complete render
    private float $time = 0.0;
    private bool $clear = true;
    /** @var array<int, array{0: int, 1: int, 2: int}> */
    private array $palette = [];

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->threads = Tasks::threaded() && ($params['mode'] ?? 'threads') === 'threads';
        $this->spot = (int)($params['spot'] ?? 0) % count(Fractal::SPOTS);
        $this->time = 0.0;
        $this->lastTime = 0.0;
        $this->sdl()->hasTexture('fractal') || $this->sdl()->createTexture('fractal', self::W, self::H);
        $this->palette = [];
        for ($i = 0; $i < 256; $i++) {
            $a = $i * 0.07;
            $this->palette[] = [(int)(128 + 127 * sin($a)), (int)(128 + 127 * sin($a + 2.1)), (int)(128 + 127 * sin($a + 4.2))];
        }
        $this->palette[0] = [0, 0, 0];   // inside the set
        $this->pool = $this->engine->tasks()->pool();
        $this->start();
    }

    public function exit(): void
    {
        $this->pool?->close();
        $this->pool = null;
    }

    private function start(): void
    {
        $this->generation++;
        $this->fresh = [];
        $this->done = 0;
        $this->nextRow = 0;
        $this->clear = true;
        $this->started = Time::now();
        if (!$this->threads) {
            return;                     // update() computes rows itself
        }
        [$cx, $cy, $span] = Fractal::SPOTS[$this->spot];
        $generation = $this->generation;
        for ($y = 0; $y < self::H; $y++) {
            // static closure + plain arguments: this is copied into a worker thread
            $this->pool?->submit(static fn (int $y) => Fractal::row($y, self::W, self::H, $cx, $cy, $span, self::ITER), $y)
                ->then(function (string $row) use ($y, $generation): void {
                    if ($generation === $this->generation) {   // a row of an older render arrives late: ignore it
                        $this->rowDone($y, $row);
                    }
                });
        }
    }

    private function rowDone(int $y, string $row): void
    {
        $this->fresh[$y] = $row;
        if (++$this->done === self::H) {
            $this->lastTime = Time::now() - $this->started;
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        if ($this->actions()->isPressed('next') || ($this->demo && $this->done === self::H && $this->time > 1)) {
            $this->spot = ($this->spot + 1) % count(Fractal::SPOTS);
            if ($this->demo) {
                $this->threads = Tasks::threaded() && !$this->threads;   // the smoke test exercises both modes
            }
            $this->start();
        }
        if ($this->actions()->isPressed('mode') && Tasks::threaded()) {
            $this->threads = !$this->threads;
            $this->start();
        }
        if (!$this->threads) {
            // same work on the main thread: only a few ms per frame, or the game would freeze
            [$cx, $cy, $span] = Fractal::SPOTS[$this->spot];
            $end = Time::now() + 0.004;
            while ($this->nextRow < self::H && Time::now() < $end) {
                $this->rowDone($this->nextRow, Fractal::row($this->nextRow, self::W, self::H, $cx, $cy, $span, self::ITER));
                $this->nextRow++;
            }
        }
    }

    public function render(Renderer $r): void
    {
        $this->flushRows($r);
        $r->clear([10, 10, 24]);
        $font = $this->font();
        $font->draw($r, 'HELLO THREADS', 8, 6, 2, [255, 210, 63]);
        $r->drawRect(7, 23, self::W + 2, self::H + 2, [80, 80, 120]);
        $r->copy($this->sdl()->getTexture('fractal')['texture'], null, 8, 24, self::W, self::H);

        $x = 208;
        $info = [
            ['PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . (PHP_ZTS ? ' ZTS' : ''), [200, 200, 220]],
            [self::BACKENDS[Tasks::backend()], Tasks::threaded() ? [120, 230, 120] : [230, 150, 90]],
            ['', [0, 0, 0]],
            ['MODE', [140, 140, 170]],
            [$this->threads ? ($this->pool?->workerCount() ?? 1) . (Tasks::backend() === 'workers' ? ' WORKERS' : ' THREADS') : 'MAIN THREAD', [255, 255, 255]],
            ['ROWS ' . $this->done . '/' . self::H, [255, 255, 255]],
            ['TIME ' . number_format($this->done === self::H ? $this->lastTime : Time::now() - $this->started, 2) . 'S', [255, 255, 255]],
            ['FPS ' . $this->engine->fps->fps, [255, 255, 255]],
        ];
        foreach ($info as $i => [$text, $color]) {
            $font->draw($r, $text, $x, 26 + $i * 11, 1, $color);
        }
        $r->fillRect($x, 122, 104, 6, [40, 40, 60]);
        $r->fillRect($x, 122, 104 * $this->done / self::H, 6, [255, 210, 63]);
        // proof the main thread is free: this keeps turning smoothly while the rows are computed
        $a = $this->time * 4;
        $r->drawLine($x + 52 - cos($a) * 14, 150 - sin($a) * 14, $x + 52 + cos($a) * 14, 150 + sin($a) * 14, [120, 200, 255]);
        $r->fillCircle($x + 52 + cos($a) * 14, 150 + sin($a) * 14, 2, [120, 200, 255]);
        $hint = Tasks::threaded() ? 'SPACE NEXT   M MODE' : 'SPACE NEXT';
        $font->draw($r, $hint, 8, 171, 1, [110, 110, 140]);
    }

    /** Draws the rows that arrived into the fractal texture (runs of the same colour = one rectangle). */
    private function flushRows(Renderer $r): void
    {
        if (!$this->clear && $this->fresh === []) {
            return;
        }
        $previous = $r->setTarget($this->sdl()->getTexture('fractal')['texture']);
        if ($this->clear) {
            $r->clear([0, 0, 0]);
            $this->clear = false;
        }
        foreach ($this->fresh as $y => $row) {
            $start = 0;
            for ($x = 1; $x <= self::W; $x++) {
                if ($x === self::W || $row[$x] !== $row[$start]) {
                    $r->fillRect($start, $y, $x - $start, 1, $this->palette[ord($row[$start])]);
                    $start = $x;
                }
            }
        }
        $this->fresh = [];
        $r->setTarget($previous);
    }
}
