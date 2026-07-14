# Fonts

The site uses two typefaces, both from Google Fonts.

| Family | Style | Weights used | Where |
|---|---|---|---|
| **Lora** | serif | 400, 500, italic 400 | Brand wordmark, all headings, quotes, card titles, sequences |
| **Outfit** | sans-serif | 300, 400, 500, 600 | Body copy, labels, buttons, nav, badges |

## Current setup (works out of the box)

`index.html` loads both fonts via the Google Fonts CDN in the `<head>`:

```html
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&family=Lora:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
```

Nothing further is required — the fonts will load automatically when the page is online.

## Optional: self-hosting the fonts (recommended for production)

Self-hosting improves reliability (no third-party dependency), privacy (no request to Google), and offline capability. To do it:

1. Download the font files. The easiest route is **google-webfonts-helper** (gwfh.mranftl.com):
   - Search "Lora" → select styles: regular (400), 500, italic (400) → download.
   - Search "Outfit" → select styles: 300, regular (400), 500, 600 → download.
   - Prefer **woff2** (and woff as fallback).
2. Place the downloaded font files in this `assets/fonts/` folder.
3. Replace the Google Fonts `<link>` in `index.html` with a local `@font-face` block, for example:

```css
@font-face {
  font-family: 'Lora';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url('assets/fonts/lora-400.woff2') format('woff2');
}
@font-face {
  font-family: 'Lora';
  font-style: italic;
  font-weight: 400;
  font-display: swap;
  src: url('assets/fonts/lora-italic-400.woff2') format('woff2');
}
@font-face {
  font-family: 'Lora';
  font-style: normal;
  font-weight: 500;
  font-display: swap;
  src: url('assets/fonts/lora-500.woff2') format('woff2');
}
@font-face {
  font-family: 'Outfit';
  font-style: normal;
  font-weight: 300;
  font-display: swap;
  src: url('assets/fonts/outfit-300.woff2') format('woff2');
}
/* repeat for Outfit 400, 500, 600 */
```

4. Keep the existing `font-family: 'Lora', serif;` and `font-family: 'Outfit', sans-serif;` rules in the CSS — they already reference these names.

## Licensing

Both Lora and Outfit are licensed under the **SIL Open Font License (OFL)** — free for commercial use, including self-hosting and embedding. No fees or attribution required.
