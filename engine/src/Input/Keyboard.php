<?php

namespace JumPHP\Input;

/**
 * Keyboard state by SDL key code (\SDLK_UP, \SDLK_SPACE, \SDLK_a ...):
 * held = down now, pressed = went down this frame, released = went up this frame.
 */
class Keyboard
{
    /** @var array<int, bool> */
    private array $held = [];
    /** @var array<int, bool> */
    private array $pressed = [];
    /** @var array<int, bool> */
    private array $released = [];
    /** @var int[] held keys, the last pressed at the end */
    private array $order = [];
    /** @var int[] keys that went down this frame, in order (text typing) */
    private array $typed = [];

    public function handle($event): void
    {
        $key = $event->key->keysym->sym;
        if ($event->type === \SDL_KEYDOWN) {
            $this->down($key);
        } elseif ($event->type === \SDL_KEYUP) {
            $this->up($key);
        }
    }

    public function down(int $key): void
    {
        if (!isset($this->held[$key])) {
            // auto-repeat sends KEYDOWN again while held: only the first one is a press
            $this->pressed[$key] = true;
            $this->order[] = $key;
            $this->typed[] = $key;
        }
        $this->held[$key] = true;
    }

    public function up(int $key): void
    {
        $this->released[$key] = true;
        unset($this->held[$key]);
        $this->order = array_values(array_filter($this->order, fn (int $k) => $k !== $key));
    }

    public function isKeyHeld(int $key): bool
    {
        return isset($this->held[$key]);
    }

    public function isKeyPressed(int $key): bool
    {
        return isset($this->pressed[$key]);
    }

    public function isKeyReleased(int $key): bool
    {
        return isset($this->released[$key]);
    }

    /** The most recently pressed key still held (left + right: the last one wins). */
    public function getLastKeyPressed(): ?int
    {
        return $this->order === [] ? null : end($this->order);
    }

    /** @return int[] keys that went down this frame */
    public function getTyped(): array
    {
        return $this->typed;
    }

    public function anyPressed(): bool
    {
        return $this->pressed !== [];
    }

    /** End of frame: pressed / released only last one frame. */
    public function resetTransientStates(): void
    {
        $this->pressed = [];
        $this->released = [];
        $this->typed = [];
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->held) as $key) {
            $this->up($key);
        }
    }
}
