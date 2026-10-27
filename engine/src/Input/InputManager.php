<?php

namespace JumPHP\Input;

use Evenement\EventEmitter;
use JumPHP\Renderer\Renderer;

/**
 * Drains the SDL event queue once per frame and feeds keyboard, mouse, touch and gamepads.
 * Events: 'quit' (window closed), 'key' (int $sym, on key down), 'resize' (int $w, int $h).
 */
class InputManager extends EventEmitter
{
    public function __construct(
        private Keyboard $keyboard,
        private Mouse $mouse,
        private Touch $touch,
        private Gamepad $gamepad,
        private ActionMap $actions,
    ) {
    }

    public function poll(?Renderer $renderer = null): void
    {
        $event = new \SDL_Event();
        while (\SDL_PollEvent($event)) {
            switch ($event->type) {
                case \SDL_QUIT:
                    $this->emit('quit');
                    break;
                case \SDL_KEYDOWN:
                    $this->keyboard->handle($event);
                    $this->emit('key', [$event->key->keysym->sym]);
                    break;
                case \SDL_KEYUP:
                    $this->keyboard->handle($event);
                    break;
                case \SDL_MOUSEMOTION:
                    $this->moveMouse($renderer, $event->motion->x, $event->motion->y);
                    break;
                case \SDL_MOUSEBUTTONDOWN:
                case \SDL_MOUSEBUTTONUP:
                    $this->moveMouse($renderer, $event->button->x, $event->button->y);
                    $event->type === \SDL_MOUSEBUTTONDOWN ? $this->mouse->down($event->button->button) : $this->mouse->up($event->button->button);
                    break;
                case \SDL_FINGERDOWN:
                case \SDL_FINGERUP:
                case \SDL_FINGERMOTION:
                    $this->touch->handle($event);
                    break;
                case \SDL_JOYBUTTONDOWN:
                case \SDL_JOYBUTTONUP:
                case \SDL_JOYAXISMOTION:
                    $this->gamepad->handle($event);
                    break;
                case 0x605: // SDL_JOYDEVICEADDED (no constant in the binding)
                    $this->gamepad->open();
                    break;
                case \SDL_WINDOWEVENT:
                    if ($event->window->event === \SDL_WINDOWEVENT_SIZE_CHANGED) {
                        $this->emit('resize', [$event->window->data1, $event->window->data2]);
                    }
                    break;
            }
        }
    }

    private function moveMouse(?Renderer $renderer, float $x, float $y): void
    {
        if ($renderer !== null) {
            // window coordinates are logical pixels; the renderer output may be HiDPI
            [$x, $y] = $renderer->windowToView($x, $y);
        }
        $this->mouse->move($x, $y);
    }

    /** End of frame: "pressed this frame" states are forgotten. */
    public function endFrame(): void
    {
        $this->keyboard->resetTransientStates();
        $this->mouse->resetTransientStates();
        $this->touch->resetTransientStates();
        $this->gamepad->resetTransientStates();
        $this->actions->endFrame();
    }

    public function getKeyboard(): Keyboard { return $this->keyboard; }
    public function getMouse(): Mouse { return $this->mouse; }
    public function getTouch(): Touch { return $this->touch; }
    public function getGamepad(): Gamepad { return $this->gamepad; }
    public function getActions(): ActionMap { return $this->actions; }
}
