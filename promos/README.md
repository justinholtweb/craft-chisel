# Plugin Store promo images

Marketing images for the Chisel listing on the Craft Plugin Store, in the theme of
[justinholt.com/plugins/craft-chisel](https://justinholt.com/plugins/craft-chisel).

```bash
./build.sh          # all slides
./build.sh "2 4"    # just slides 2 and 4
```

Output: `out/chisel-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, downsampled). JPEG, never PNG.
`fonts.css` is generated and gitignored. The cover and closing slides carry the Craft 6 beta caution, and every
footer says "Craft CMS 6 (beta)" — keep it until Craft 6 and Chisel leave beta.

| # | Slide |
| --- | --- |
| 1 | Cover — free, Craft 6 beta caution |
| 2 | A form for every command |
| 3 | Allowlisted — and some never |
| 4 | Live output, secrets masked |
| 5 | Audit log, CSV, read-only GraphQL |
| 6 | Closing — summary, beta caution |
