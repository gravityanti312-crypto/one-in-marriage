# ONE in Marriage — Website Developer Handoff

**Design:** "Warm Minimal" (approved)
**Program:** ONE in Marriage — A Pattern-Aware Marriage Experience
**Domain:** ONEinMarriage.com
**Handoff date:** July 2026

---

## 1. What this package contains

```
one-in-marriage-website/
├── index.html                  ← the complete, final website (single file)
├── README.md                   ← this document
├── SPEC.md                     ← full design spec: colors, type, spacing, sections
├── CONTENT.md                  ← all site copy in plain text (for proofing / CMS)
├── CHANGELOG.md                ← notes on what this design is and isn't
└── assets/
    ├── images/
    │   ├── hero-couple.jpg      ← hero background image (placeholder — see §4)
    │   └── IMAGE-CREDITS.md     ← source + licensing notes and the exact swap to make
    └── fonts/
        └── FONTS.md             ← fonts used + how to self-host if desired
```

The site is a **single self-contained `index.html`** — all CSS and JavaScript are inline. There is no build step, no framework, no npm install. Open it in a browser and it runs.

---

## 2. Quick start

1. Keep the folder structure exactly as above (`index.html` must sit next to the `assets/` folder).
2. Open `index.html` in any modern browser to preview.
3. To publish: upload the whole folder to any static host (Netlify, Vercel, Cloudflare Pages, S3, or a traditional web server). No server-side code is required.

That's it. The site will render exactly as designed.

---

## 3. Fonts

Two Google Fonts are loaded via CDN in the `<head>`:

- **Lora** (serif) — headings, quotes, brand wordmark
- **Outfit** (sans-serif) — body copy, labels, buttons

The CDN link is already in `index.html` and works out of the box. If you prefer to **self-host** the fonts (recommended for production reliability, privacy, and offline use), see `assets/fonts/FONTS.md` for the exact steps and the character/weights to include.

---

## 4. IMPORTANT — the hero image is a placeholder

`assets/images/hero-couple.jpg` is a warm, on-brand **abstract placeholder** (soft golden light and sage tones). It matches the palette and is safe to ship, but it is **not the intended final image**.

**The design calls for a real photograph of a couple** in warm, natural, golden-hour light — intimate and hopeful, not staged or corporate. The original comp used this licensed Unsplash photo:

> https://unsplash.com/photos/photo-1529333166437-7750a6dd5a70

**To use the real photo:** download it from Unsplash (free license, attribution appreciated), rename it to `hero-couple.jpg`, and drop it into `assets/images/` to replace the placeholder. No code change needed — the filename is already wired up.

See `assets/images/IMAGE-CREDITS.md` for full details, licensing, and recommended dimensions.

---

## 5. Buttons and links that need wiring

The design is visually complete but the calls-to-action are **placeholders** (`href="#"`). Before launch, point these to real destinations:

- **"Begin the experience"** (hero + closing CTA) → registration / signup page or form
- **"Request a conversation"** (closing CTA) → contact form or scheduling link (e.g. Calendly)
- **"See how it works"** (hero) → currently anchors to the Experience section; leave as-is or repoint
- **Nav links** (The Patterns / The Experience / Begin) → already anchor-linked to on-page sections; no change needed unless the IA changes

There is currently **no contact form, no analytics, and no cookie/consent banner** — these are intentionally left for the developer to add per the client's stack and jurisdiction.

---

## 6. What's already handled

- ✅ Fully responsive (desktop, tablet, mobile — breakpoint at 820px)
- ✅ Sticky navigation with a blur backdrop
- ✅ Smooth-scroll anchor navigation
- ✅ Hover states on cards and buttons
- ✅ Accessible color contrast and semantic HTML headings
- ✅ Print-friendly styles (the `@media print` block controls page breaks for PDF export)
- ✅ No external dependencies except the two Google Fonts

---

## 7. Accessibility & SEO to finish before launch

These are standard pre-launch items the design leaves open for the developer:

- Add a descriptive `alt` attribute to the hero image once the real photo is in (the hero is currently a CSS background; if you convert it to an `<img>`, include alt text).
- Add `<meta name="description">` and Open Graph / Twitter card tags for social sharing.
- Add a favicon and touch icons.
- Confirm heading order (h1 → h2) is preserved if sections are added.
- Run Lighthouse and address any contrast or tap-target flags.

---

## 8. Questions

The design spec in `SPEC.md` documents every color, font size, and spacing value so the site can be rebuilt or extended pixel-accurately. If anything is ambiguous, `SPEC.md` is the source of truth for the visual system, and `CONTENT.md` is the source of truth for the words.
