# Notes & Context

## What this design is

This is the **"Warm Minimal"** direction — the approved design for ONE in Marriage. It was selected from four explored directions (Cinematic, Editorial, Warm Minimal, Bold Modern). The warm-minimal aesthetic was chosen because it is the most welcoming and lowest-pressure — appropriate for couples who may feel nervous about relationship work.

## Content model

The copy reflects the program's approved centerline: **ONE in Marriage — A Pattern-Aware Marriage Experience.** The site is built around:
- The idea that couples get caught in **patterns they cannot see** (not a divorce-prevention frame).
- The **formation sequence** (Event → Meaning → Feeling → Decision → Lens → Pattern).
- The **Five Lived Capacities** (Emotional Safety, Pattern Awareness, Lens Fluency, Repair & Return, Shared Creation).
- The **Eight Lenses** (Savior, Survivor, Prover, Martyr, Analyst, Endurer, Ghost, Chameleon).

## Deliberate choices (please preserve unless the client requests otherwise)

- **No statistics or research citations.** Earlier drafts cited Gottman/Pew/CDC/NCFMR data; the client intentionally removed all of it. The soul of the program is transformation and safety, not data. Do not re-introduce statistics.
- **No "you should have done this earlier" framing.** The program meets couples wherever they are.
- **Warm palette only** — no white/clinical backgrounds, no corporate blues.
- **Calm, static design** — this direction intentionally avoids heavy scroll animations.

## Consistency note

The hero vision line on the site matches the approved program vision:
> "Marriage becomes extraordinary when two people are safe enough to be honest, loved enough to grow, and committed enough to become one — not by avoiding the storms, but by becoming a safe harbor for each other."

If the vision statement is ever revised, update it in **both** the website (`index.html` hero + `CONTENT.md`) and the program report document to keep them in sync.

## Known open items (for the developer to complete before launch)

1. Replace the placeholder hero image with the real couple photo (see `assets/images/IMAGE-CREDITS.md`).
2. Wire the CTA buttons to real destinations (registration + contact/scheduling).
3. Add meta description, Open Graph tags, and a favicon.
4. Add analytics and (if required by jurisdiction) a cookie/consent banner.
5. Optionally self-host fonts (see `assets/fonts/FONTS.md`).
6. Run a final Lighthouse / accessibility pass.

## Rebuild guarantee

Everything needed to reproduce the design exactly is in this package: `index.html` is the complete implementation, `SPEC.md` documents every design token, and `CONTENT.md` holds every word. There is no hidden build step or external dependency beyond the two Google Fonts (which can be self-hosted).
