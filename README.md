# signlab_body-animation-viewer
Babylon.js viewer for GLB body animations reconstructed from SignCollect recordings, with gloss and sense search.

## What it does
- `index.html`: search by gloss or file name and load the matching GLBs from `s3d_files/`. Each clip plays trimmed to its VTT segment. You can queue signs into a sentence.
- `gebarenstrand.html` (old name `gs.html` redirects here): a fixed manifest of 5,428 GLBs with readable names. `gebarenstrand_to_m.json` maps them to `M…` ids. Clips play in full.
- `api.php?action=`: `list&q=` (up to 100 GLB names by gloss or file name), `glossmap`, `senseindex`, `vttset`, and `upload` (POST one `.glb` into `s3d_files/`). `glossmap`, `senseindex` and `vttset` cache their result in `*_cache.json` for a week; `list` searches the gloss cache and the disk.

## Where it runs
Core server: `/web/s3b_glb`, https://signcollect.nl/s3b_glb/. Not on the demo hosts. No page links to it.

## Status
Experimental (no auth, no tests).

## How to run / deploy
`repos.tsv` does not list it. Copy the tree to `/web/s3b_glb`, then:
```bash
ln -s /mnt/bigstorage/s3d_files/ /web/s3b_glb/s3d_files   # the symlink is in git, the target is not
chown www-data /web/s3b_glb                                # api.php writes its caches here
```
The first request after a deploy rebuilds the caches, which is slow.

## Configuration
- `<root>/mysql_config.php`. `<root>` comes from the vendored `sc_paths.php`: `SC_WEB_ROOT`, default `/web`. Edit `sc_paths.php` in [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib), not here.
- Not in git: `*_cache.json` (safe to delete) and three CNGT 720p reference MP4s (about 550 MB) on the server.

## Dependencies
- MySQL `admin_gebarenoverleg`, read-only: `matched_transcriptions`, `form_data`, `sentences`, `nmm_data`.
- VTT sidecars in `/web/gebarenoverleg_media/studioFilesMini/raw/`, shared with [signlab_sam3d-body-queue](https://github.com/Amsterdam-Humanities-Labs/signlab_sam3d-body-queue).
- Babylon.js from `cdn.babylonjs.com`, version not pinned.

## License and citation

Apache License 2.0, copyright University of Amsterdam: see [LICENSE](LICENSE) and
[NOTICE](NOTICE). You may use it, also commercially, as long as you credit
Gomer Otterspeer / University of Amsterdam as the source. To cite it, use
[CITATION.cff](CITATION.cff) (the *Cite this repository* button on GitHub) or the DOI [10.21942/uva.33980311](https://doi.org/10.21942/uva.33980311).
