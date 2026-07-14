# ONE in Marriage — Design Specification ("Warm Minimal")

This is the source of truth for the visual system. Every value below is already implemented in `index.html`; this document exists so the site can be rebuilt, extended, or QA'd pixel-accurately.

---

## Color palette

All colors are defined as CSS custom properties in `:root`.

| Token | Hex | Role |
|---|---|---|
| `--bg` | `#FBF7F0` | Page background (warm cream) |
| `--card` | `#FFFFFF` | Card surfaces |
| `--card-warm` | `#F3ECE0` | Warm accent panels (guide block, CTA, badges) |
| `--ink` | `#3D3A34` | Primary text |
| `--ink-soft` | `#7A746A` | Secondary / muted text |
| `--sage` | `#8BA888` | Primary accent (buttons, checkmarks) |
| `--sage-deep` | `#5E7A5C` | Deep accent (headings' italic words, brand) |
| `--peach` | `#E8B596` | Warm accent (light) |
| `--peach-deep` | `#C98860` | Warm accent (labels, eyebrows) |
| `--line` | `#ECE4D6` | Hairline borders |

**Usage rules**
- Section eyebrow labels use `--peach-deep`.
- The italicized word inside each headline uses `--sage-deep`.
- Primary buttons: `--sage` background, white text; hover → `--sage-deep`.
- Secondary/"quiet" buttons: white background, `--ink` text, `--line` border; hover border → `--sage`.

---

## Typography

**Families (Google Fonts)**
- **Lora** — serif. Weights: 400, 500; italic 400. Used for: brand wordmark, all headings, quotes, card titles, the formation/movement sequences.
- **Outfit** — sans-serif. Weights: 300, 400, 500, 600. Used for: body copy, eyebrow labels, buttons, nav, badges.

Base body text is Outfit 300 (light) at 17px, line-height 1.75.

**Type scale (desktop)**
| Element | Font | Size | Weight | Notes |
|---|---|---|---|---|
| Hero H1 | Lora | clamp(2.6rem → 4.4rem) | 400 | italic word in `--sage-deep` |
| Section H2 | Lora | clamp(2rem → 3rem) | 400 | centered |
| Card / capacity title | Lora | 1.1–1.2rem | 500 | |
| Eyebrow label | Outfit | 0.82rem | 500 | uppercase, letter-spacing 0.16em, `--peach-deep` |
| Body / sub | Outfit | 1.1–1.25rem | 300 | `--ink-soft` for sub-text |
| Button | Outfit | 0.95rem | 400 | |
| Quote (guide/arc) | Lora italic | clamp(1.5rem → 2.2rem) | 400 | |

---

## Layout & spacing

- **Content width:** `.wrap` max-width **1080px**, centered, 2rem side padding.
- **Section rhythm:** `section { padding: 4.5rem 0; }`
- **Card radius:** 18–24px depending on element; CTA panel 40px; hero image 32px.
- **Grid gaps:** 1–1.5rem.
- **Responsive breakpoint:** **820px.** Below it: nav text links hide (Begin pill stays), multi-column grids collapse to 1–2 columns, lens grid → 2 columns.

---

## Section inventory (in order)

1. **Nav** — sticky, blurred cream backdrop. Logo "ONE in **Marriage**" (Marriage in `--sage-deep`). Links: The Patterns · The Experience · Begin (pill).
2. **Hero** — centered. Badge → H1 "Stop becoming the **storm** for each other." → vision sub-copy → two buttons → hero image band.
3. **The Patterns** (`#patterns`) — "It's not the fight. It's the **pattern.**" + five escalating "whisper" lines.
4. **The Core Truth** (`#framework`) — headline + the formation sequence: *Event → Meaning → Feeling → Decision → Lens → Pattern.*
5. **Guide block** (`#guide`) — warm panel, two-column: a lived-example quote + "We don't diagnose / We slow the moment down."
6. **The Experience** (`#capacities`) — "Five capacities you **live**, not memorize." + 5 numbered capacity cards.
7. **Lens Fluency** (`#lenses`) — "Every spouse sees through **a lens.**" + 8-card lens grid (4×2).
8. **How Each Session Moves** (`#movement`) — the movement sequence: *Story → Recognition → Experience → Reflection → Practice → Agreement.*
9. **Arc line** — "From two people caught in patterns they cannot see — to two people who see, soften, repair, and create together."
10. **Safety note** — sage-tinted panel; the safeguarding statement.
11. **Closing CTA** (`#register`) — "Become **ONE** in Marriage." + two buttons.
12. **Footer** — brand + "ONEinMarriage.com · A Pattern-Aware Marriage Experience".

---

## Interaction / motion

- Sticky nav with `backdrop-filter: blur(10px)`.
- Smooth scroll via `scroll-behavior: smooth`.
- Card hover: subtle lift (`translateY(-4px)`) + soft shadow on plan cards.
- Button hover: color shift (primary) / border shift (quiet).
- No scroll-triggered animation library is required; the design reads cleanly static. (The earlier cinematic design used scroll reveals; this Warm Minimal design intentionally does not depend on them.)

---

## Print styles

An `@media print` block is included so the page exports to PDF with clean page breaks (sections avoid breaking mid-content; the closing CTA starts on its own page). This is what generated the review PDFs and can be left as-is.
