<?php

namespace App\Antiriad;

/**
 * One game: the rules of the original, run one tick at a time (the original updates its objects about
 * 15 times a second). The scene gives the controls of the tick and plays the sounds listed in $sounds.
 *
 *  - Tal on foot: walks, jumps, throws stones, crouches to pick something up. His life (#B4FB, 31) drops
 *    by the "bite" of what touches him (type byte 1, bits 4-0); an enemy that touches him dies of it.
 *    The caves are radioactive: on foot, every 2 seconds, a little life is lost (#6F45).
 *  - The suit (room 63) is inert until the anti-gravity boots are found; then it walks and flies. Inside,
 *    the bites hit its energy (#B4FD, 31), halved (and halved again with the particle negator), and Tal's
 *    life comes back. Recharge cells fill the energy. In / out: the suit key, or crouching on it.
 *  - Modules: boots (fly), pulse beam (shoot), implosion mine, particle negator (rooms 0 and 1).
 *  - The end: in the suit, the mine dropped on the base's core (room 0). Five lives ("regenerations": Tal gets up where he fell).
 */
final class Game
{
    public const TICK = 1 / 15;                // the main loop's pace: Tal walks a byte every 3.3 CPC frames
    public const MAX_LIFE = 31;
    public const MAX_ENERGY = 31;
    public const BOOTS = 0;
    public const PULSE = 1;
    public const MINE = 2;
    public const NEGATOR = 3;
    private const PERIOD = 30;                  // #6F08: every 100 frames of the CPC, 2 seconds
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
    private bool $shownInert = false;
    private bool $lowWarned = false;
    private int $suitCooldown = 0;
    private int $hazardCooldown = 0;
    /** the room's first object (the original's slot 2): the big head that drops bombs, the base's turret */
    private ?Actor $leader = null;
    /** its missile (the original's first free slot after the room's objects): one at a time */
    private ?Actor $missile = null;
    /** the shot's slot (#B413): the stone, then its burst; and the size its last sprite left there */
    private ?Actor $shot = null;
    /** @var array{int, int} */
    private array $shotSize = [0, 0];
    /** ticks left of Tal getting into the suit (#5D9E: 12, Tal and the suit flickering), -1: no */
    public const ENTER_TICKS = 21;
    /** #6235: main loop turns before a teleporter brings the room's other objects */
    private const TELEPORT_TICKS = 40;
    private ?Actor $teleporter = null;
    /** @var list<list<int>> the objects a teleporter brings: x, y, program, type */
    private array $pending = [];
    private int $teleportIn = 0;
    /** a teleporter's room: its hum loops */
    public bool $hum = false;
    /** ticks left of Tal's fading into the suit, -1 = not getting in */
    public int $entering = -1;
    private ?Actor $enteringSuit = null;
    /** the dashboard: lit (pens of the suit), the suit's first start (#604F: the flicker of #8E12) */
    public bool $dashLit = false;
    /** the dashboard shows the score and the dial (#6112: after the first time in the suit) */
    public bool $dashOn = false;
    /** the bars shown (#5DFC: they follow the values by one a tick); -1: not yet (#5DDA: the suit started) */
    public int $shownLife = -1;
    public int $shownEnergy = -1;
    private int $flickerStep = -1;
    private int $dyingTicks = 0;
    /** #8E12: the suit's first start, one bit a tick (1 = lit), 7 bits a byte, 0 = the end */
    private const FLICKER = [170, 128, 192, 152, 199, 176, 97, 206, 155, 0];

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
            $this->hero->enterSuit();
            $this->energy = (int)($params['energy'] ?? self::MAX_ENERGY);
            $this->suit = [-1, 0, 0];
            $this->dashOn = $this->dashLit = true;
            $this->shownLife = $this->life;
            $this->shownEnergy = $this->energy;
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
        $this->pending = [];
        $this->teleporter = null;
        // #7192: the objects up to a teleporter (#29, always the first); those after it wait (#B54F = 40)
        foreach ($this->world->rooms[$room]['objects'] as $o) {
            if ($this->teleporter !== null) {
                $this->pending[] = $o;
                continue;
            }
            [$x, $y, $program, $type] = $o;
            $a = new Actor($this->world, $type, $x, $y, $program);
            $a->appear();                         // #6A50: every object comes in through the masks
            $this->actors[] = $a;
            if ($type === 0x29) {
                $this->teleporter = $a;
                $this->teleportIn = self::TELEPORT_TICKS;
            }
        }
        $this->leader = $this->actors[0] ?? null;
        $this->missile = null;
        foreach ($this->world->cells as $i => [$r, $x, $y]) {
            if ($r === $room && empty($this->cellsTaken[$i])) {
                $a = new Actor($this->world, 0x1C, $x, $y);
                $a->appear();
                $a->itemIndex = $i;
                $this->actors[] = $a;
            }
        }
        foreach ($this->world->modules as $i => [$r, $x, $y]) {
            if ($r === $room && !$this->modules[$i]) {
                $a = new Actor($this->world, 0x1D + $i, $x, $y);
                $a->appear();
                $a->itemIndex = 100 + $i;
                $this->actors[] = $a;
            }
        }
        if (!$this->hero->inSuit && $this->suit[0] === $room) {
            $a = new Actor($this->world, 0x36, $this->suit[1], $this->suit[2]);
            $a->appear();
            $this->actors[] = $a;
        }
        $this->hum = $this->teleporter !== null;  // #7184: a teleporter hums (#0D) as long as it is there
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
        match ($side) {                           // #69C2...: fixed places in the next room
            'right' => $h->x = 0x06,
            'left' => $h->x = 0x44,
            'up' => $h->y = 0x99,
            default => $h->y = 0x1B,
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

    /**
     * #6235: 40 turns after Tal came in, a teleporter still there gives way to the room's other objects
     * (#7192 from the next one, into its slot); shot down before, it brings none.
     */
    private function teleporterTick(): void
    {
        $t = $this->teleporter;
        if ($t === null) {
            return;
        }
        if ($this->teleportIn > 0) {
            if ($t->dead || $t->fade < 0) {       // shot down (or faded) before: nothing comes
                $this->teleporter = null;
                $this->pending = [];
                $this->hum = false;
            } elseif (--$this->teleportIn === 0) {
                $t->vanish();                     // #626D: it fades away (12 turns)...
            }
            return;
        }
        if (!$t->dead) {
            return;
        }
        $this->teleporter = null;                 // ...then the others come in its slot (#7192), appearing
        $this->hum = false;
        $first = null;
        foreach ($this->pending as [$x, $y, $program, $type]) {
            $a = new Actor($this->world, $type, $x, $y, $program);
            $a->appear();
            $first ??= $a;
            $this->actors[] = $a;
        }
        $this->pending = [];
        $this->leader = $first;
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
        $this->dashboardTick();
        if ($h->dying()) {                        // the room goes on while he dies (nothing hurts him)
            $this->dyingTicks++;
            if ($h->dyingTick($this->room, $this->dyingTicks)) {
                $this->regenerate();
            }
            foreach ($this->actors as $a) {
                $a->tick($h->x, $h->y);
            }
            $this->missiles();
            $this->actors = array_values(array_filter($this->actors, fn (Actor $a) => !$a->dead));
            return;
        }
        if ($this->entering >= 0) {
            $this->enteringTick();
        } else {
            $h->inSuit ? $this->suitTick($in) : $this->footTick($in);
            $this->roomEdges();
        }
        $this->teleporterTick();
        $saucer = false;
        foreach ($this->actors as $a) {
            $was = $a->frame & 0x7F;
            $a->tick($h->x, $h->y);
            // #5547: a saucer's whine, each time its animation (#3B) comes back to sprite #6E
            $saucer = $saucer || ($a->frame & 0x7F) === 0x6E && $was !== 0x6E;
        }
        if ($saucer) {
            $this->sound('saucer');
        }
        $this->missiles();
        if ($this->entering < 0) {
            $this->collisions($in);
        }
        $this->shotSlot();
        $this->actors = array_values(array_filter($this->actors, fn (Actor $a) => !$a->dead));
        if ($this->tick % self::PERIOD === 0) {
            $this->periodic();
        }
        if (!$h->inSuit && $this->life <= 0 && !$this->demo && $this->entering < 0) {
            $this->die();
        }
    }

    /** The dashboard: the suit's first start flickers it (#604F), then the bars follow the values (#5DDA). */
    private function dashboardTick(): void
    {
        if ($this->flickerStep >= 0) {
            $i = $this->flickerStep++;
            // #605A: the first byte gives its bits 2-7, the next ones their bits 1-7
            $byte = $i < 6 ? 0 : 1 + intdiv($i - 6, 7);
            $bit = $i < 6 ? 2 + $i : 1 + ($i - 6) % 7;
            $value = self::FLICKER[$byte];
            if ($value === 0) {                  // #6077: the suit is alive
                $this->flickerStep = -1;
                $this->shownLife = $this->shownEnergy = 0;
                $this->say(17);
                $this->sound('activate');
            } else {
                $this->dashLit = (($value >> $bit) & 1) === 1;
            }
        }
        if ($this->shownLife >= 0) {
            $this->shownLife += $this->life <=> $this->shownLife;
            $this->shownEnergy += $this->energy <=> $this->shownEnergy;
        }
    }

    private function footTick(array $in): void
    {
        $h = $this->hero;
        $h->flying = false;
        if ($h->releasing() && !$this->shotFlying()) {
            $this->throwStone();
        }
        foreach ($h->footTick($this->room, $in) as $event) {
            if ($event === 'down') {
                $this->changeRoom('down');
            } elseif ($event === 'land') {
                $this->sound('step');
            }
        }
        if ($h->footstep()) {
            $this->sound('step');
        }
    }

    private function suitTick(array $in): void
    {
        $h = $this->hero;
        $h->flying = false;
        if ($in['suit'] && $h->onGround || $h->anim === Hero::SUIT_IN && ($in['left'] || $in['right'])) {
            $this->leaveSuit();                   // #6150: left / right when it has come down facing the player
            return;
        }
        $works = $this->modules[self::BOOTS] && $this->energy > 0 && $this->flickerStep < 0;
        $wants = $in['left'] || $in['right'] || $in['up'] || $in['jump'];
        if (!$works) {
            if ($wants && !$this->shownInert && $this->flickerStep < 0) {
                $this->say($this->energy <= 0 ? 16 : 13);
                $this->shownInert = true;
            }
        } else {
            $this->shownInert = false;
            $moves = $h->suitControl($in);
            if ($moves & 4) {
                $h->step($this->room, -1);
            } elseif ($moves & 8) {
                $h->step($this->room, 1);
            }
            // the boots: the suit hangs in the air (up / down move it) until it comes down on the ground
            $h->flying = $h->anim !== Hero::SUIT_IN;
            if ($moves & 1) {
                $h->rise = -1;
                $h->up($this->room, 3);
                $h->onGround = false;
                $h->fallIndex = 0;
            } elseif ($moves & 2 && $h->descend($this->room, 3)) {
                $h->suitLanded();
                $h->flying = false;
            }
            if ($moves !== 0 && $this->tick % 8 === 0) {
                $this->useEnergy(1);
            }
        }
        if ($in['fire']) {
            if (!$this->modules[self::PULSE]) {
                $this->say(14);
            } elseif ($works && !$this->shotFlying()) {
                $this->fireBeam();
            }
        }
        if ($h->fall($this->room) === 'down') {   // inert, or settled: it falls
            $this->changeRoom('down');
        }
    }

    private function roomEdges(): void
    {
        // #69A7: x < #05 left, x >= #45 right, y < #19 up (y >= #9B down: Hero::fall)
        $h = $this->hero;
        $up = $this->world->exit($this->room, 'up') !== null;
        if ($up && $h->y < 0x19) {
            $this->changeRoom('up');
        } elseif (!$up && $h->y < World::TOP) {
            $h->y = World::TOP;
            $h->rise = -1;
        }
        if ($h->x >= 0x45 && !$this->changeRoom('right')) {
            $h->x = 66;
        }
        if ($h->x < 0x05 && !$this->changeRoom('left')) {
            $h->x = 8;
        }
        if ($this->world->exit($this->room, 'right') === null) {
            $h->x = min($h->x, 66);
        }
        if ($this->world->exit($this->room, 'left') === null) {
            $h->x = max($h->x, 8);
        }
    }

    /**
     * #5598: the big head (#27) drops a bomb (#2E, program #23) from 5 bytes right and #15 lines down of
     * itself, one at a time; a bomb is gone at line #A0. #5642: the base's turret (#2B) fires a beam (#32
     * then #33, program #29) from x #1B line #7F, gone at x #46.
     */
    private function missiles(): void
    {
        $m = $this->missile;
        if ($m !== null && !$m->dead && ($m->type === 0x2E && $m->y >= 0xA0 || $m->type === 0x33 && $m->x >= 0x46)) {
            $m->dead = true;
        }
        $l = $this->leader;
        if ($l === null || $l->dead || $m !== null && !$m->dead) {
            return;
        }
        if ($l->type === 0x27) {
            $this->missile = new Actor($this->world, 0x2E, $l->x + 5, $l->y + 0x15, 0x23);
        } elseif ($l->type === 0x2B) {
            $this->missile = new Actor($this->world, 0x33, 0x1B, 0x7F, 0x29);   // harmful as #33 (#922A)
            $this->missile->setAnim(0x32);
            $l->setAnim(0x2B);
            $this->sound('shoot');
        } else {
            return;
        }
        $this->actors[] = $this->missile;
    }

    /** #B413 + 15 / 16: the shot's slot keeps the size of the last sprite drawn in it (a stone shows from its 5th tick). */
    private function shotSlot(): void
    {
        $s = $this->shot;
        if ($s === null) {
            return;
        }
        if ($s->kind === Actor::FX || $s->age > 4) {
            $this->shotSize = [$s->width(), $s->height()];
        }
        if ($s->dead) {
            $this->shot = null;
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
            $this->sound('rad' . $zone);                          // #6F66: the sound #19 + zone
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

    /** #6905 / #6910: the stone leaves at frame 5 of the throw, 6 lines down, and flies an arc (program 1 or 2). */
    private function throwStone(): void
    {
        $h = $this->hero;
        $right = $h->facing > 0;
        $a = new Actor($this->world, 0x2C, $h->x + ($right ? 6 : -2), $h->y + 6, $right ? 1 : 2);
        $a->kind = Actor::SHOT;
        $a->damage = 1;                           // #5D2B: a stone takes one hit, the pulse beam two
        $a->life = 60;
        $a->hitSize = $this->shotSize;            // #5937: tested where it leaves the hand, with the slot's old size
        $this->shot = $a;
        $this->actors[] = $a;                     // silent in the original
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

    /**
     * #5937: two objects touch when they are closer than the width of the left one (bytes) and the height
     * of the upper one (lines). [x, y, w, h] each; the hanging beast (#2F) only when Tal is right of it.
     */
    private function touch(array $a, Actor $b): bool
    {
        if (($b->animId() & 0x7F) === 0x2F && $this->hero->x < $b->x) {
            return false;
        }
        [$bw, $bh] = [$b->width(), $b->height()];
        $w = $a[0] >= $b->x ? $bw : $a[2];
        $h = $a[1] >= $b->y ? $bh : $a[3];
        return abs($a[0] - $b->x) < $w && abs($a[1] - $b->y) < $h;
    }

    private function collisions(array $in): void
    {
        $h = $this->hero;
        $hb = [$h->x, $h->y, Hero::W, Hero::H];
        foreach ($this->actors as $a) {
            if ($a->dead) {
                continue;
            }
            if ($a->kind === Actor::SHOT) {
                foreach ($this->actors as $e) {
                    if ($e->kind === Actor::ENEMY && !$e->dead && !$e->fading() && $this->touch([$a->x, $a->y, ...$a->hitSize()], $e)) {
                        $this->hitEnemy($e, $a->damage);
                        $a->dead = true;              // #5D6F: the shot bursts (#2D) where it is
                        $fx = new Actor($this->world, 0x2D, $a->x, max(World::TOP, $a->y - 4));
                        $fx->kind = Actor::FX;
                        $this->actors[] = $fx;
                        if ($a === $this->shot) {
                            $this->shot = $fx;
                        }
                        break;
                    }
                }
                continue;
            }
            if ($a->fading() || !$a->visible() || !$this->touch($hb, $a)) {
                continue;
            }
            switch ($a->kind) {
                case Actor::ENEMY:
                case Actor::HAZARD:
                    $this->bite($a);
                    break;
                case Actor::BEAM:                 // #5BFF: a separating line, it only sounds when crossed
                    if ($this->tick % 4 === 0) {
                        $this->sound('beam');
                    }
                    break;
                case Actor::ITEM:
                    $this->take($a, $in);
                    break;
                case Actor::SUIT:                 // #6094: Tal standing facing the player gets in
                    if (($h->facingPlayer() || $in['suit']) && $h->onGround && $this->suitCooldown === 0 && $this->life > 0) {
                        $this->enterSuit($a);
                        return;
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
            if ($a->type === 0x2F && !$h->inSuit && $h->crouched()) {
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
        if ($a->armoured) {
            return;                               // #5BC4: no sound, no score, it stays
        }
        $this->score += ($h->inSuit ? $a->points >> 1 : $a->points) * 10;   // #5BCE: what bites dies for points
        $this->sound('hurt');
        if ($a->kind === Actor::ENEMY) {
            $a->vanish();                         // #5BED: it fades away
        }
    }

    private function hitEnemy(Actor $e, int $damage): void
    {
        $e->hits -= $damage;
        $e->flash = 3;
        if ($e->hits <= 0) {                      // #5D4C: it dies (#13), else it is hit (#1E)
            $e->vanish();
            $this->score += ($damage === 1 ? $e->points : $e->points >> 1) * 10;   // #5D54: half with the beam
            $this->sound('hurt');
        } else {
            $this->sound('hit');
        }
    }

    private function die(): void
    {
        $this->hero->die();                       // the bite's sound only: the tune is for getting up
        $this->dyingTicks = 0;
    }

    /** #15: a regeneration: Tal gets up where he fell, his life full again; the room goes on as it was. */
    private function regenerate(): void
    {
        $this->lives--;
        if ($this->lives <= 0) {
            $this->over = true;
            $this->say(6);
            return;
        }
        $this->say([1 => 5, 2 => 4, 3 => 3][$this->lives] ?? 2);
        $h = $this->hero;
        $h->getUp();
        $this->sound('regen');                    // #10-#12 on the three channels, as he gets up
        $this->life = self::MAX_LIFE;
        if ($this->room <= 1 && !$this->modules[self::NEGATOR]) {
            // the base kills at once without the negator: get up in the suit's room instead
            $room = $this->suit[0] >= 0 ? $this->suit[0] : $this->world->start[0][0];
            [$h->x, $h->y] = $this->suit[0] >= 0 ? [min(66, $this->suit[1] + 7), $this->suit[2]] : [$this->world->start[0][1], $this->world->start[0][2]];
            $this->enterRoom($room);
        }
    }

    private function take(Actor $a, array $in): void
    {
        $h = $this->hero;
        if (!$h->inSuit && !$h->pickingUp()) {    // #5D85: going down on his knees picks it up
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

    /** #6094: Tal fades out in front of the suit (#5D9E: 12 dissolve masks, ~1.4 s), then it is his (#60D3). */
    private function enterSuit(Actor $a): void
    {
        $this->entering = self::ENTER_TICKS;
        $this->enteringSuit = $a;
        $this->sound('enter');
    }

    private function enteringTick(): void
    {
        if (--$this->entering > 0) {
            return;
        }
        $this->entering = -1;
        $h = $this->hero;
        $a = $this->enteringSuit;
        $this->enteringSuit = null;
        if ($a !== null) {
            $a->dead = true;
            $h->x = $a->x;
            $h->y = $a->y - 1;
        }
        $h->enterSuit();
        $h->onGround = true;
        $this->suit = [-1, 0, 0];
        $this->dashLit = true;                    // #61BB: the suit's pens light up
        if ($this->carryingCell) {
            $this->carryingCell = false;
            $this->refill();
        }
        if (!$this->dashOn) {                     // #611C: the first time, the dashboard starts
            $this->dashOn = true;
            $this->flickerStep = 0;
            $this->say(17);
            $this->sound('suit');
        }
    }

    /** #6150: the suit stays where it stands, Tal steps out of it facing the player. */
    private function leaveSuit(): void
    {
        if (self::zone($this->room) >= 5) {
            $this->say(21);                       // #61B5: no getting out in the base
            return;
        }
        $h = $this->hero;
        $h->inSuit = false;
        $h->flying = false;
        $this->suit = [$this->room, $h->x, $h->y + 1];
        $this->actors[] = new Actor($this->world, 0x36, $h->x, $h->y + 1);
        $h->stand();
        $this->suitCooldown = 40;                 // #6180: #B517 = #28
        $this->dashLit = false;                   // #61D2
        $this->sound('leave');
    }
}
