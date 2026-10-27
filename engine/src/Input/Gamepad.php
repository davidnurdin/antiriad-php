<?php

namespace JumPHP\Input;

/**
 * Joysticks / gamepads through SDL joystick events (the binding has no SDL_GameController API):
 * buttons by number, axes as directions. Names used in config/input.yaml:
 *   pad0:button0 ... pad0:button15, pad0:left / right / up / down (left stick, dead zone 0.35)
 * Button numbers depend on the pad; most XInput pads: 0 = A, 1 = B, 2 = X, 3 = Y, 6 = Back, 7 = Start.
 */
class Gamepad
{
    private const DEAD_ZONE = 11500; // of 32767

    /** @var array<int, mixed> opened joysticks */
    private array $joysticks = [];
    /** @var array<string, bool> */
    private array $held = [];
    /** @var array<string, bool> */
    private array $pressed = [];

    public function open(): void
    {
        if (!function_exists('SDL_NumJoysticks')) {
            return;
        }
        for ($i = 0, $n = \SDL_NumJoysticks(); $i < $n; $i++) {
            $this->joysticks[$i] ??= \SDL_JoystickOpen($i);
        }
    }

    public function count(): int
    {
        return count($this->joysticks);
    }

    public function handle($event): void
    {
        if ($event->type === \SDL_JOYBUTTONDOWN || $event->type === \SDL_JOYBUTTONUP) {
            $b = $event->jbutton;
            $this->set("pad{$b->which}:button{$b->button}", $event->type === \SDL_JOYBUTTONDOWN);
        } elseif ($event->type === \SDL_JOYAXISMOTION) {
            $a = $event->jaxis;
            if ($a->axis > 1) {
                return;
            }
            [$neg, $pos] = $a->axis === 0 ? ['left', 'right'] : ['up', 'down'];
            $this->set("pad{$a->which}:$neg", $a->value < -self::DEAD_ZONE);
            $this->set("pad{$a->which}:$pos", $a->value > self::DEAD_ZONE);
        }
    }

    private function set(string $name, bool $on): void
    {
        if ($on && !isset($this->held[$name])) {
            $this->pressed[$name] = true;
        }
        if ($on) {
            $this->held[$name] = true;
        } else {
            unset($this->held[$name]);
        }
    }

    public function isHeld(string $name): bool
    {
        return isset($this->held[$name]);
    }

    public function isPressed(string $name): bool
    {
        return isset($this->pressed[$name]);
    }

    public function anyPressed(): bool
    {
        return $this->pressed !== [];
    }

    public function resetTransientStates(): void
    {
        $this->pressed = [];
    }
}
