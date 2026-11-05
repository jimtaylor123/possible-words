# Possible Words: three design directions

These are working design explorations for issue #4, awaiting Jim's choice.

| Direction | Local preview | Character |
| --- | --- | --- |
| 01 · Word Lab | http://127.0.0.1:8005/?design=lab | Electric lime, orbital letterforms, precise discovery cards |
| 02 · The Unwritten | http://127.0.0.1:8005/?design=folio | Literary serif type, warm paper tones, dictionary-inspired entries |
| 03 · Word Playground | http://127.0.0.1:8005/?design=play | Lavender, playful stickers, chunky type and outlined cards |

The bar above navigation switches designs on any page. Dark is the initial theme; light mode and the design choice persist locally. Direct preview URLs select the requested design. Browse real words, open details and try search and filters. The isolated preview contains the committed 1,000-word seed data.

The preview runs on this Mac. To check or restart it from this worktree:

```sh
node scripts/manage-hat-preview.mjs status 4
node scripts/manage-hat-preview.mjs start 4
```

To stop it:

```sh
node scripts/manage-hat-preview.mjs stop 4
```

The launcher is copied from the main checkout's local preview tooling. It records current URLs and service details in `.agents/preview/4.json`. If the original ports become occupied after stopping, a restart can choose new ports: use its printed URL and append `?design=lab`, `?design=folio` or `?design=play`.

Choose a direction or a combination before the final production polish. This preview does not close the umbrella issue or publish a change.
