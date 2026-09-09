# s3b_glb

Browser viewer for the GLB body animations reconstructed from the signCollect
sign collection, with search over glosses and senses.

**Status: experimental.** See *Status* below before relying on anything here.

## What it does

Two single-file Babylon.js pages over one PHP endpoint:

- `index.html` — the general viewer. Type a gloss or a filename, `api.php`
  answers with matching `.glb` names, the page loads them from `s3d_files/` and
  plays them back, trimmed to the VTT segment of the source recording where one
  exists. A queue lets you chain several signs into a sentence.
- `gs.html` — the *Gebarenstrand* variant. Same viewer, but scoped to a fixed
  manifest of 5,428 files whose names are readable Dutch glosses
  (`aap_eva_side_M_video_anim.glb`), mapped back to their `M…` ids by
  `gebarenstrand_to_m.json` (5,606 entries). VTT trimming is deliberately
  disabled here — it always plays the full clip.

`api.php` has five actions: `list` (search), `glossmap` (filename → gloss label,
built from the database), `senseindex` (Dutch word → signs that carry that sense),
`vttset` (filename → `[start, end]` parsed out of the VTT sidecars), and `upload`
(accepts a single `.glb` into `s3d_files/`). The three read-heavy actions cache
their result to a JSON file next to `api.php` and rebuild it once a week.

## Where it runs

The **signcollect core server** (production VPS), at `/web/s3b_glb`, served as
<https://signcollect.nl/s3b_glb/>.

It is **not** deployed to the demo hosts (dev2 `/web`, dev-1
`/srv/signcollect/web`). A grep across everything the demo portal can reach
found no reference to it, so nothing on those hosts links here.

## Status

Experimental — a working prototype that is still up, not a maintained service.

- The GitHub repository was created 2026-08-17 as an import of a directory that
  already existed on the server. Its entire history is two housekeeping commits
  (add `.gitignore`, untrack the generated caches). There is no development
  history here at all, so "when was this last worked on" cannot be answered from
  git.
- Both pages and all five API actions respond correctly on production today, so
  it is not broken and not deleted.
- Nothing links to it. It is reached by typing the URL.
- There is a stray zero-byte file named `1` in the root, committed by accident.

Expect prototype-grade code: no tests, no auth, no error pages, `api.php`
`require`s the production credentials file by absolute path.

## Size and how to clone

The git repository is small — about **1.8 MB** — so clone it normally:

```bash
git clone git@github.com:Amsterdam-Humanities-Labs/signlab_s3b_glb.git
```

The **~536 MB** figure people quote is the deployed *directory* on the server,
and almost all of it is untracked: three Corpus NGT 720p reference videos
(`CNGT1592_S067_b_720.mp4` 229 MB, `CNGT0254_S014_b_720.mp4` 242 MB,
`CNGT0476_S024_b_720.mp4` 79 MB) plus the ~4 MB of generated caches. Those are
in `.gitignore` and you do not get them from a clone. The GLB collection itself
is not in that total either — `s3d_files` is a symlink to
`/mnt/bigstorage/s3d_files/`.

## Deploying

There is no build step. Copy the tree to `/web/s3b_glb` and then, on the host:

```bash
ln -s /mnt/bigstorage/s3d_files/ /web/s3b_glb/s3d_files   # symlink is tracked, target is not
chown www-data /web/s3b_glb                                # api.php writes its caches here
```

The caches (`gloss_cache.json`, `sense_index_cache.json`, `vtt_set_cache.json`)
regenerate themselves on first request; the first one after a deploy is slow.

## Configuration (not in git)

| What | Where it comes from |
|---|---|
| `/web/mysql_config.php` | The host's single credential file. `api.php` `require`s it by that absolute path — it is not read relative to this directory, so this app only runs on a host that has one there. |
| `s3d_files` → `/mnt/bigstorage/s3d_files/` | The GLB collection, on the big storage volume. |
| `*_cache.json` | Generated. Safe to delete; they rebuild. |

## Dependencies

- **MySQL `admin_gebarenoverleg`**, tables `matched_transcriptions`, `form_data`,
  `sentences`, `nmm_data` — read-only, owned by the signCollect suite.
- **`/web/gebarenoverleg_media/studioFilesMini/raw/`** for the `.vtt` sidecars
  used to trim playback (the same directory `s3b_server` and `s3b_viewer` work
  from).
- **Babylon.js** from `cdn.babylonjs.com`, unpinned — the pages need outbound
  internet in the browser.
