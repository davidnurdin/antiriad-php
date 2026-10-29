<?php

namespace App\Antiriad;

/**
 * One game: the rules of the original, run one tick at a time (the original updates its objects about
 * 12 times a second). The scene gives the controls of the tick and plays the sounds listed in $sounds.
 *
 *  - Tal on foot: walks, jumps, throws stones, crouches to pick something up. His life (#B4FB, 31) drops
 *    by the "bite" of what touches him (type byte 1, bits 4-0); an enemy that touches him dies of it.
 *    The caves are radioactive: on foot, every 2 seconds, a little life is lost (#6F45).
 *  - The suit (room 63) is inert until the anti-gravity boots are found; then it walks and flies. Inside,
 *    the bites hit its energy (#B4FD, 31), halved (and halved again with the particle negator), and Tal's
 *    life comes back. Recharge cells fill the energy. In / out: the suit key, or crouching on it.
 *  - Modules: boots (fly), pulse beam (shoot), implosion mine, particle negator (rooms 0 and 1).
 *  - The end: in the suit, the mine dropped on the base's core (room 0). Five lives ("rejuvenations").
 */
final class Game
{
    public const TICK = 1 / 12;
    public const MAX_LIFE = 31;
    public const MAX_ENERGY = 31;
    public const BOOTS = 0;
    public const PULSE = 1;
    public const MINE = 2;
    public const NEGATOR = 3;
    private const PERIOD = 24;                  // #6F08: every 100 frames of the CPC, 2 seconds
    // module index -> its message (#5A2D...)
    private const MODULE_MESSAGE = [8, 9, 11, 10];
    // #8B37: rooms from which the radiation zone number drops by one (zone 6 = rooms 0-1 ... 0 = 41+)
    private const ZONES = [2, 6, 13, 19, 26, 41];

    public Hero $hero;
    public int $room;
    /** @var list<Actor> */
    public array $actors = [];
    public int $lives = 5;
    public int $score = 0;
    public int $life = self::MAX_LIFE;
    public int $energy = self::MAX_ENERGY;
    /** @var array<int, bool> */
    public array $modules = [false, false, false, false];
    /** @var array<int, bool> */
    public array $cellsTaken = [];
    public bool $carryingCell = false;
    /** where the empty suit stands: [room, x, y] */
    public array $suit;
    /** @var list<string> sounds of this tick */
    public array $sounds = [];
    /** @var list<int> messages to show (ids of the original's table) */
    public array $messages = [];
    public ?int $countdown = null;
    public bool $won = false;
    public bool $over = false;
    public bool $demo = false;
    public int $flash = 0;
    public int $tick = 0;
    private array $entry = [];
    private bool $shownInert = false;
    private bool $lowWarned = false;
    private int $suitCooldown = 0;
    private int $hazardCooldown = 0;

    public function __construct(private World $world, array $params = [])
    {
        $this->hero = new Hero($world);
        $this->suit = $world->armour;
        $start = $world->start[0];
        $this->room = (int)($params['room'] ?? $start[0]);
        $this->hero->x = (int)($params['x'] ?? $start[1]);
        $this->hero->y = (int)($params['y'] ?? $start[2]);
        $this->demo = !empty($params['demo']);
        foreach ((array)($params['modules'] ?? []) as $m) {
            $this->modules[(int)$m] = true;
        }
        if (isset($params['lives'])) {
            $this->lives = (int)$params['lives'];
        }
        if (!empty($params['suit'])) {
            $this->hero->inSuit = true;
            $this->energy = (int)($params['energy'] ?? self::MAX_ENERGY);
            $this->suit = [-1, 0, 0];
        }
        $this->enterRoom($this->room);
    }

    private function sound(string $name): void
    {
        $this->sounds[] = $name;
    }

    private function say(int $message): void
    {
        $this->messages[] = $message;
    }

    // ---------------------------------------------------------------- rooms

    /** Builds the room's objects (#7104...): its enemies, the items still there, the empty suit. */
    public function enterRoom(int $room): void
    {
        $this->room = $room;
        $this->actors = [];
        foreach ($this->world->rooms[$room]['objects'] as [$x, $y, $program, $type]) {
            $this->actors[] = new Actor($this->world, $type, $x, $y, $program);
        }
        foreach ($this->world->cells as $i => [$r, $x, $y]) {
            if ($r === $room && empty($this->cellsTaken[$i])) {
                $a = new Actor($this->world, 0x1C, $x, $y);
                $a->itemIndex = $i;
                $this->actors[] = $a;
            }
        }
        foreach ($this->world->modules as $i => [$r, $x, $y]) {
            if ($r === $room && !$this->modules[$i]) {
                $a = new Actor($this->world, 0x1D + $i, $x, $y);
                $a->itemIndex = 100 + $i;
                $this->actors[] = $a;
            }
        }
        if (!$this->hero->inSuit && $this->suit[0] === $room) {
            $this->actors[] = new Actor($this->world, 0x36, $this->suit[1], $this->suit[2]);
        }
        $this->entry = [$this->hero->x, $this->hero->y];
        if ($room <= 1) {                       // #7113: the base is irradiated
            if ($this->modules[self::NEGATOR]) {
                $this->say(21);
            } else {
                $this->say(18);
                $this->life = 0;
            }
        }
    }

    private function changeRoom(string $side): bool
    {
        $next = $this->world->exit($this->room, $side);
        if ($next === null) {
            return false;
        }
        $h = $this->hero;
        match ($side) {
            'right' => $h->x -= 64,
            'left' => $h->x += 64,
            'up' => $h->y += 128,
            default => $h->y -= 128,
        };
        $this->enterRoom($next);
        return true;
    }

    public function palette(): int
    {
        return $this->world->rooms[$this->room]['palette'];
    }

    /** #72C5: the radiation zone of a room (6 = the base ... 0 = the jungle). */
    public static function zone(int $room): int
    {
        $zone = 6;
        foreach (self::ZONES as $from) {
            if ($room < $from) {
                break;
            }
            $zone--;
        }
        return $zone;
    }

    // ---------------------------------------------------------------- the tick

    /** @param array<string, bool> $in held: left right up down; pressed: fire suit jump */
    public function tick(array $in): void
    {
        $this->sounds = [];
        $this->tick++;
        if ($this->flash > 0) {
            $this->flash--;
        }
        if ($this->won || $this->over) {
            return;
        }
        if ($this->countdown !== null) {
            $this->flash = ($this->countdown % 6 < 3) ? 2 : 0;
            if (--$this->countdown <= 0) {
                $this->won = true;
            }
        }
        $h = $this->hero;
        foreach (['suitCooldown', 'hazardCooldown'] as $timer) {
            if ($this->$timer > 0) {
                $this->$timer--;
            }
        }
        if ($h->invulnerable > 0) {
            $h->invulnerable--;
        }
        if ($h->pose === 'die') {
            $h->animate();
            if ($h->poseDone()) {
                $this->respawn();
            }
            return;
        }
        $h->inSuit ? $this->suitTick($in) : $this->footTick($in);
        $this->roomEdges();
        foreach ($this->actors as $a) {
            $a->tick($h->x, $h->y);
        }
        $this->collisions($in);
        $this->actors = array_values(array_filter($this->actors, fn (Actor $a) => !$a->dead));
        if ($this->tick % self::PERIOD === 0) {
            $this->periodic();
        }
        if (!$h->inSuit && $this->life <= 0 && !$this->demo) {
            $this->die();
        }
        $h->animate();
    }

    private function footTick(array $in): void
    {
        $h = $this->hero;
        $h->flying = false;
        if (in_array($h->pose, ['throw', 'land'], true) && $h->poseDone()) {
            $h->setPose('idle');
        }
        $busy = in_array($h->pose, ['throw', 'land'], true);
        if ($h->releasing()) {
            $this->throwStone();
        }
        $dir = ($in['right'] ? 1 : 0) - ($in['left'] ? 1 : 0);
        if (!$busy) {
            if ($in['down'] && $h->onGround) {
                $h->setPose('crouch');
                $dir = 0;
            } elseif ($in['fire'] && $h->onGround && !$this->shotFlying()) {
                $h->setPose('throw');
                $dir = 0;
            } elseif (($in['jump'] || $in['up']) && $h->onGround) {
                $h->jump();
            }
            if ($dir !== 0) {
                $h->step($this->room, $dir);
            }
            if ($h->onGround && !in_array($h->pose, ['crouch', 'throw'], true) || $h->pose === 'crouch' && !$in['down']) {
                $h->setPose($dir !== 0 ? 'walk' : 'idle');
            }
            if ($h->pose === 'walk' && $h->poseTick % 4 === 0) {
                $this->sound('step');
            }
        }
        if ($h->fall($this->room) === 'down') {
            $this->changeRoom('down');
        }
        if (!$h->onGround && $h->pose !== 'throw') {
            $h->setPose('air');
        }
    }

    private function suitTick(array $in): void
    {
        $h = $this->hero;
        $dir = ($in['right'] ? 1 : 0) - ($in['left'] ? 1 : 0);
        $wants = $dir !== 0 || $in['up'] || $in['jump'];
        $h->flying = false;
        if ($in['suit']) {                        // leave the suit where it stands
            $this->leaveSuit();
            return;
        }
        $works = $this->modules[self::BOOTS] && $this->energy > 0;
        if (!$works) {
            if ($wants && !$this->shownInert) {
                $this->say($this->energy <= 0 ? 16 : 13);
                $this->shownInert = true;
            }
        } else {
            $this->shownInert = false;
            if ($dir !== 0) {
                $h->step($this->room, $dir);
            }
            if ($in['up'] || $in['jump']) {       // the boots: thrust, costs energy
                $h->flying = true;
                $h->rise = -1;
                $h->y -= 3;
                $h->onGround = false;
                $h->fallIndex = 0;
                if ($this->tick % 8 === 0) {
                    $this->useEnergy(1);
                }
            }
            $h->setPose($dir !== 0 ? 'walk' : 'idle');
        }
        if ($in['fire']) {
            if (!$this->modules[self::PULSE]) {
                $this->say(14);
            } elseif ($works && !$this->shotFlying()) {
                $this->fireBeam();
            }
        }
        if ($h->fall($this->room) === 'down') {
            $this->changeRoom('down');
        }
    }

    private function roomEdges(): void
    {
        $h = $this->hero;
        if ($h->y < World::TOP && !$this->changeRoom('up')) {
            $h->y = World::TOP;
            $h->rise = -1;
        }
        if ($h->x >= 70 && !$this->changeRoom('right')) {
            $h->x = 66;
        }
        if ($h->x <= 2 && !$this->changeRoom('left')) {
            $h->x = 8;
        }
        if ($this->world->exit($this->room, 'right') === null) {
            $h->x = min($h->x, 66);
        }
        if ($this->world->exit($this->room, 'left') === null) {
            $h->x = max($h->x, 8);
        }
    }

    private function useEnergy(int $n): void
    {
        $this->energy = max(0, $this->energy - $n);
        if ($this->energy < 7 && $this->energy > 0 && !$this->lowWarned) {     // #62AE
            $this->say(15);
            $this->lowWarned = true;
        }
        if ($this->energy === 0 && $n > 0) {
            $this->say(16);
        }
    }

    private function refill(): void
    {
        $this->energy = self::MAX_ENERGY;
        $this->lowWarned = false;
    }

    /** #6F14, every 2 seconds: in the suit Tal heals and the base drains it; on foot the caves irradiate him. */
    private function periodic(): void
    {
        if ($this->hero->inSuit) {
            $this->life = min(self::MAX_LIFE, $this->life + 1);
            if ($this->room < 2) {
                $this->useEnergy(2);
            }
            return;
        }
        $zone = self::zone($this->room);
        if ($zone >= 2 && $zone <= 4 && !$this->demo) {
            $this->life = max(0, $this->life - intdiv($zone, 2));
            $this->sound('hurt');
        }
    }

    // ---------------------------------------------------------------- shots

    private function shotFlying(): bool
    {
        foreach ($this->actors as $a) {
            if ($a->kind === Actor::SHOT) {
                return true;
            }
        }
        return false;
    }

    private function throwStone(): void
    {
        $h = $this->hero;
        $a = new Actor($this->world, 0x2C, $h->x + ($h->facing > 0 ? 5 : 0), $h->y + 12);
        $a->kind = Actor::SHOT;
        $a->damage = 1;                           // #5D2B: a stone takes one hit, the pulse beam two
        $a->life = 12;
        $a->setMotion($h->facing > 0 ? 8 : 4, 3, 0);
        $this->actors[] = $a;
        $this->sound('shoot');
    }

    private function fireBeam(): void
    {
        $h = $this->hero;
        $a = new Actor($this->world, $h->facing > 0 ? 0x34 : 0x32, $h->x + ($h->facing > 0 ? 4 : -4), $h->y + 14);
        $a->kind = Actor::SHOT;
        $a->damage = 2;
        $a->life = 18;
        $a->setMotion($h->facing > 0 ? 8 : 4, 4, 0);
        $this->actors[] = $a;
        $this->useEnergy(1);
        $this->sound('shoot');
    }

    // ---------------------------------------------------------------- contacts

    private static function overlap(array $a, array $b): bool
    {
        return $a[0] < $b[0] + $b[2] && $b[0] < $a[0] + $a[2] && $a[1] < $b[1] + $b[3] && $b[1] < $a[1] + $a[3];
    }

    private function collisions(array $in): void
    {
        $h = $this->hero;
        $hb = $h->box();
        foreach ($this->actors as $a) {
            if ($a->dead) {
                continue;
            }
            if ($a->kind === Actor::SHOT) {
                foreach ($this->actors as $e) {
                    if ($e->kind === Actor::ENEMY && !$e->dead && self::overlap($a->box(), $e->box())) {
                        $this->hitEnemy($e, $a->damage);
                        $a->dead = true;
                        break;
                    }
                }
                continue;
            }
            if (!$a->visible() || !self::overlap($hb, $a->box())) {
                continue;
            }
            switch ($a->kind) {
                case Actor::ENEMY:
                case Actor::HAZARD:
                    $this->bite($a);
                    break;
                case Actor::BEAM:                 // #5BFF: the suit crosses it, it stops Tal
                    if ($h->inSuit) {
                        if ($this->tick % 4 === 0) {
                            $this->sound('beam');
                        }
                    } else {
                        $this->life = 0;
                    }
                    break;
                case Actor::ITEM:
                    $this->take($a, $in);
                    break;
                case Actor::SUIT:
                    if (($in['suit'] || $in['down']) && $this->suitCooldown === 0) {
                        $this->enterSuit($a);
                    }
                    break;
                case Actor::TELEPORT:             // #5ABD: back to the suit
                    if ($in['down'] && !$h->inSuit && $this->suit[0] >= 0 && $this->suit[0] !== $this->room) {
                        $this->say(7);
                        $h->x = min(66, $this->suit[1] + 7);
                        $h->y = $this->suit[2];
                        $this->enterRoom($this->suit[0]);
                        return;
                    }
                    break;
                case Actor::CORE:                 // #5C0E: the mine on the core
                    if ($h->inSuit && $this->modules[self::MINE] && $this->countdown === null) {
                        $this->say(19);
                        $this->countdown = 12 * 12;
                        $this->sound('suit');
                    }
                    break;
            }
        }
    }

    /** #5B1A: what touches Tal bites his life, or the suit's energy; an enemy dies of it. */
    private function bite(Actor $a): void
    {
        $h = $this->hero;
        if ($h->invulnerable > 0 || $this->demo) {
            return;
        }
        if ($a->kind === Actor::HAZARD) {
            if ($this->hazardCooldown > 0) {
                return;
            }
            if ($a->type === 0x2F && !$h->inSuit && $h->pose === 'crouch') {
                return;                           // #5B36: the hanging beast misses a crouching Tal
            }
            $this->hazardCooldown = 8;            // #5CD1
        }
        $bite = max(1, $a->damage);
        if ($h->inSuit) {
            $bite = max(1, $bite >> 1);
            if ($this->modules[self::NEGATOR]) {
                $bite = max(1, $bite >> 1);
            }
            $this->useEnergy($bite);
        } else {
            $this->life = max(0, $this->life - $bite);
        }
        $h->invulnerable = 3;
        $this->sound('hurt');
        if ($a->kind === Actor::ENEMY) {
            $this->explode($a);
        }
    }

    private function hitEnemy(Actor $e, int $damage): void
    {
        $e->hits -= $damage;
        $e->flash = 3;
        $this->sound('hit');
        if ($e->hits <= 0) {
            $this->explode($e);
            $this->score += $e->points * 10;
        }
    }

    private function explode(Actor $e): void
    {
        $e->dead = true;
        $fx = new Actor($this->world, 0x2D, $e->x, $e->y);
        $fx->kind = Actor::FX;
        $this->actors[] = $fx;
    }

    private function die(): void
    {
        $h = $this->hero;
        $h->setPose('die');
        $h->rise = -1;
        $this->sound('death');
    }

    private function respawn(): void
    {
        $this->lives--;
        if ($this->lives <= 0) {
            $this->over = true;
            $this->say(6);
            return;
        }
        $this->say([1 => 5, 2 => 4, 3 => 3][$this->lives] ?? 2);
        $h = $this->hero;
        [$h->x, $h->y] = $this->entry;
        $h->rise = -1;
        $h->fallIndex = 0;
        $h->onGround = false;
        $h->setPose('idle');
        $h->invulnerable = 24;
        $this->life = self::MAX_LIFE;
        if ($this->room <= 1 && !$this->modules[self::NEGATOR]) {
            // no coming back to life in the base without the negator: back to the suit's room
            $room = $this->suit[0] >= 0 ? $this->suit[0] : $this->world->start[0][0];
            [$h->x, $h->y] = $this->suit[0] >= 0 ? [min(66, $this->suit[1] + 7), $this->suit[2]] : [$this->world->start[0][1], $this->world->start[0][2]];
            $this->enterRoom($room);
            return;
        }
        $this->enterRoom($this->room);
    }

    private function take(Actor $a, array $in): void
    {
        $h = $this->hero;
        if (!$h->inSuit && !($in['down'] && $h->onGround)) {     // #5D85: crouch to pick it up
            return;
        }
        if ($a->itemIndex >= 100) {
            $i = $a->itemIndex - 100;
            $this->modules[$i] = true;
            $this->say(self::MODULE_MESSAGE[$i]);
            $this->score += 500;
        } else {
            if ($h->inSuit) {
                $this->refill();
            } elseif ($this->carryingCell) {
                $this->say(1);
                return;
            } else {
                $this->carryingCell = true;
            }
            $this->cellsTaken[$a->itemIndex] = true;
            $this->say(12);
            $this->score += 100;
        }
        $a->dead = true;
        $this->sound('pickup');
    }

    private function enterSuit(Actor $a): void
    {
        $h = $this->hero;
        $a->dead = true;
        $h->inSuit = true;
        $h->x = $a->x;
        $h->y = min($h->y, $a->y);
        $h->setPose('idle');
        $this->suit = [-1, 0, 0];
        if ($this->carryingCell) {
            $this->carryingCell = false;
            $this->refill();
        }
        $this->say(17);
        $this->sound('suit');
    }

    private function leaveSuit(): void
    {
        $h = $this->hero;
        $h->inSuit = false;
        $h->flying = false;
        $this->suit = [$this->room, $h->x, $h->y];
        $this->actors[] = new Actor($this->world, 0x36, $h->x, $h->y);
        $h->x = $h->facing > 0 ? min(66, $h->x + 7) : max(8, $h->x - 7);
        $h->setPose('idle');
        $h->invulnerable = 6;
        $this->suitCooldown = 6;
    }
}
