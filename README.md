# studio-cli

Watch an Artisan Studio workflow from your own machine.

Registers a tab in `artisan dev`, beside `horizon`, `server`, `logs` and `vite` — so the artisans' work shows up in a window you already have open, and the files land in a git worktree you can run.

```
artisan dev · Artisan Studio

  01:43:12  mason    wrote app/Livewire/ProjectIntelligence.php
  01:43:15  mason    wrote resources/views/livewire/project-intelligence.blade.php
  01:44:02  prover   wrote tests/Feature/IntelligenceRegressionTest.php
  01:44:31  prover   1 failed, 0 passed
```

## Install

```bash
composer require artisan-studio-projects/studio-cli --dev
php artisan artisan-studio:link
```

`link` asks for the token (Settings → Avatar Studio → API token, shown once), checks it, and writes `ARTISAN_STUDIO_TOKEN` and `ARTISAN_STUDIO_PROJECT` to your `.env`.

Then `php artisan dev` — the tab is there.

## What it does

**Mirrors** each file an artisan writes into a git worktree outside your repository:

```
../artisan-studio-preview/art-213/
```

Your branch is untouched, `git status` never mentions it, and several workflows can be open at once. The worktree is checked out **detached**, so nothing in it can be committed by accident.

**Refuses to start** on a dirty tree. Commit or stash first — a preview should not land on top of work in progress. An untracked scratch file is fine.

## What it does not do

**It does not write back.** During AI Execution the mirror is one-directional: read the code, run the app, click through it — but your edits stay yours and do not reach the workflow.

That is not about merge conflicts. Foreman planned the tasks, Guard audits against them, and the trail records what each artisan did. An edit absorbed quietly would make the workflow claim Mason wrote something you wrote, and every artisan after it would reason about code nobody recorded — including work outside the plan, arriving invisible and uncosted.

Reading and writing do not need the same permissions. Seeing the code is safe, so it is free; authoring is gated until Human Verification, where you take the worktree over and your changes are recorded as yours.

Found a problem before then? **Challenge** the task and the artisan revises its own work, so the plan and the code stay in agreement.

## Commands

| | |
|---|---|
| `artisan-studio:link` | Connect this checkout. Interactive, run once. |
| `artisan-studio:watch` | Follow the active workflow. This is the dev tab. |
| `artisan-studio:watch --once` | Print what is running and exit. |
| `artisan-studio:watch --no-mirror` | Follow without writing any files. |
| `artisan-studio:build-presence` | Rebuild the desktop player. macOS, optional. |

## SAMI on the desktop

While you watch a build she can stand at the bottom of your screen and react to
it — cut out, full resolution, speaking. Optional in every direction: without
the player or without clips she never appears and the watcher is unchanged.

macOS only. She arrives with the tab, rests between beats, reacts once and
returns to resting, hides when you switch application, follows that terminal
between displays, and closes when the tab does.

### Her clips

One `.mov` per state in `storage/app/sami/`, filmed in Avatar Studio under the
**Dev tab** placement:

```
cli-workflow_started.mov     between things
cli-workflow_working.mov     an artisan is working
cli-workflow_handover.mov    work passing between artisans
cli-workflow_summary.mov     summing up what was built
cli-workflow_done.mov        the build finished
```

Filenames come from `AvatarState::fileStem()`: `::` becomes `_`, every other `_`
becomes `-`. A state with no clip falls back to `started`, so one clip works and
five are better. `inbound` and `outbound` both play `handover`.

### Converting them

Studio clips are keyed WebM and have to become QuickTime movies:

```bash
ffmpeg -c:v libvpx-vp9 -i keyed.webm \
  -vf "crop='min(iw,ih)':'min(iw,ih)',scale=800:807,crop=800:800:0:7" \
  -c:v prores_ks -profile:v 4444 -pix_fmt yuva444p10le -alpha_bits 8 -qscale:v 14 \
  -c:a aac -ar 44100 -ac 1 \
  storage/app/sami/cli-workflow_done.mov
```

Four flags there are load-bearing:

- `-c:v libvpx-vp9` **on the input** — ffmpeg's default VP9 decoder silently drops
  the alpha channel, with no error. Without it she arrives in a black box.
- **ProRes 4444** — the only format AVFoundation honours alpha in. HEVC-with-alpha
  from ffmpeg cannot be read by it at all.
- `-qscale:v 14` — `prores_ks` honours qscale where most ProRes encoders ignore it.
  A fifth of the size at 47.5dB PSNR.
- `scale=800:807` then `crop=800:800:0:7` — keyed WebMs carry a semi-opaque band
  across their top two rows, invisible on dark backgrounds and a clear line on light.

A silent clip still needs an audio track or anything concatenating it later drops
the stream: add `-f lavfi -i anullsrc=r=44100:cl=mono` and `-shortest`.

### Settings

```dotenv
STUDIO_CLI_PRESENCE=false          # turn her off entirely
STUDIO_CLI_PRESENCE_SIZE=300       # how tall she stands, in points (clamped 120–900)
STUDIO_CLI_PRESENCE_PLAYER=/path   # a player somewhere other than vendor
STUDIO_CLI_PRESENCE_CLIPS=/path    # clips somewhere other than storage/app/sami
```

Size is in **points**, not pixels — a Retina display draws two pixels for each,
which is why clips are filmed at 800 square and stand at 400.

### Rebuilding the player

The binary ships with the package and runs from `vendor/`. To rebuild it after
changing `src/SamiDesktop.swift`:

```bash
composer build:presence                      # from the package
php artisan artisan-studio:build-presence    # from an app that installed it
```

Universal binary for Intel and Apple Silicon. Needs Xcode command line tools.

### When she does not appear

| what you see | why |
|---|---|
| Nothing at all | Not a Mac, `STUDIO_CLI_PRESENCE=false`, or no `cli-workflow_started.mov`. A near-miss filename is silent. |
| Nothing, clips present | The player is not where the config says. A different vendor namespace needs `STUDIO_CLI_PRESENCE_PLAYER`. |
| Appears then vanishes | The clip has no alpha, or is not ProRes. |
| A black box | Converted without `-c:v libvpx-vp9` on the input, so alpha was dropped at decode. |
| Several of her | Orphaned players from a killed watcher. `pkill -f samidesktop`. |

To check a clip carries alpha:

```bash
ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,pix_fmt \
  -of csv=p=0 storage/app/sami/cli-workflow_started.mov
```

Anything but `prores` and `yuva444p*` will not carry alpha through AVFoundation.

To see a reaction without waiting for a build:

```bash
php artisan artisan-studio:trigger done
php artisan artisan-studio:trigger self --agent=mason
```

Kinds are `self`, `inbound`, `outbound`, `summary` and `done`.

## Configuration

```bash
php artisan vendor:publish --tag=studio-cli-config
```

| | |
|---|---|
| `ARTISAN_STUDIO_URL` | Where the studio lives. |
| `ARTISAN_STUDIO_TOKEN` | Written by `link`. |
| `ARTISAN_STUDIO_PROJECT` | Written by `link`. |
| `ARTISAN_STUDIO_WORKTREE` | Where previews go. Default `../artisan-studio-preview`. |
| `ARTISAN_STUDIO_DEV_TAB` | `false` keeps the commands without the tab. |

You can also drop the tab from your own provider, which outranks this package either way:

```php
DevCommands::except('Artisan Studio');
```

## Requirements

PHP 8.3+, Laravel 13+. The dev tab needs `artisan dev`, which arrived in Laravel 13; on earlier versions run `artisan-studio:watch` yourself.

## How it listens

Server-sent events, read through `Process::forever()->start()` rather than an HTTP client.

Guzzle — and Saloon on top of it — wants a response that ends: it buffers until the body is complete and hands the whole thing back. A watched workflow never completes, so the events would sit in a buffer nobody reads. A subprocess inverts that: the output closure fires as bytes arrive, and the command outlives any timeout a request-shaped client would impose.

Reconnects resume from the last event id, so a dropped connection costs a gap rather than a replay of everything an artisan wrote today.
