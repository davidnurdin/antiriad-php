# hello-world — notes for agents

The smallest JumPHP game, kept small on purpose (tutorials, the playground's sandbox start from it).

- `TitleScene` (MenuScene): PLAY -> HelloScene, SOUND on / off (`SoundManager::setMuted`), QUIT (desktop only).
- `HelloScene`: "HELLO WORLD!" moved by the arrows, a Synth sound and a burst of stars on Space / Enter / a
  tap, Escape back to the title. `enter(['demo' => true])` is the self-playing demo of the smoke test.
- No asset: sounds are made with `Synth`, text with the built-in bitmap font.
