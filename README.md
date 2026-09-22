# signlab_s3b_glb
Babylon.js viewer for the GLB body animations reconstructed from the signCollect collection, with gloss and sense search.

## What it does
- `index.html`: search a gloss or filename, load matching GLBs from `s3d_files/`, play trimmed to the VTT segment; queue signs into a sentence.
- `gs.html` (Gebarenstrand): fixed manifest of 5,428 readable-name GLBs, mapped to `M…` ids by `gebarenstrand_to_m.json`; plays full clips.
- `api.php` actions: `list`, `glossmap`, `senseindex`, `vttset`, `upload` (one `.glb` into `s3d_files/`). Read actions cache to `*_cache.json` for a week.

## Where it runs
core (production): `/web/s3b_glb`, https://signcollect.nl/s3b_glb/. Not on the demo hosts; nothing links to it.

## Status
experimental (no auth, no tests)

## How to run / deploy
Not in repos.tsv; copy the tree to `/web/s3b_glb`, then:
```bash
ln -s /mnt/bigstorage/s3d_files/ /web/s3b_glb/s3d_files   # symlink tracked, target not
chown www-data /web/s3b_glb                                # api.php writes its caches here
```
First request after a deploy rebuilds the caches (slow).

## Configuration
- `/web/mysql_config.php`, required by absolute path.
- Not in git: `*_cache.json` (safe to delete) and three CNGT 720p reference MP4s (~550 MB) on the server.

## Dependencies
- MySQL `admin_gebarenoverleg` (read-only): `matched_transcriptions`, `form_data`, `sentences`, `nmm_data`.
- `/web/gebarenoverleg_media/studioFilesMini/raw/` for VTT sidecars (shared with s3b_server).
- Babylon.js from `cdn.babylonjs.com`, unpinned.
