# Image Credits & Instructions

## Files in this folder

### hero-couple.jpg  — PLACEHOLDER
- **Current state:** an abstract, on-brand background (warm golden light + soft sage tones) generated to match the site palette. It is royalty-free and safe to ship as-is.
- **Intended final image:** a real photograph of a couple in warm, natural, golden-hour light — intimate, hopeful, and human. Not staged, not corporate stock.
- **Where it appears:** the hero band near the top of the page (set as a CSS `background-image` on `.hero-visual`).
- **Recommended dimensions:** at least **1600×1100px**, landscape. It is displayed with `object-fit: cover` behavior inside a rounded 32px container, so the center of the image stays visible — keep the focal point roughly centered.
- **File size:** aim for under ~300KB (compress/export at ~80–85% JPEG quality) for fast loading.

## To use the original comp photo

The approved design comp used this licensed Unsplash photograph:

> **URL:** https://unsplash.com/photos/photo-1529333166437-7750a6dd5a70
> **Source:** Unsplash
> **License:** Unsplash License (free to use commercially, no permission needed; attribution appreciated but not required)

**Steps:**
1. Download the photo from Unsplash at high resolution.
2. Resize/crop to ~1600×1100 (landscape), keeping the focal point centered.
3. Compress to ~80–85% JPEG quality.
4. Name it exactly `hero-couple.jpg` and place it in this folder, replacing the placeholder.

No code change is needed — `index.html` already points at `assets/images/hero-couple.jpg`.

## If you choose a different photo

Any warm, golden-hour couple photo will work. Just match the naming and dimensions above. If you switch to an `<img>` element instead of a CSS background, remember to add descriptive `alt` text for accessibility (e.g. "A couple embracing in warm evening light").

## Attribution note

If the client's legal/brand guidelines require photo credits, add the Unsplash photographer's name to a site credits page or the footer. The Unsplash License does not require it, but it is good practice.

---

## Actual image currently in use (dev note)

`hero-couple.jpg` currently in this folder is a warm, golden-hour couple photo from **Pexels**
(https://www.pexels.com/photo/1024993/), used under the **Pexels License** (free for commercial
use, no attribution required). It was chosen as an on-brand stand-in because the sandbox that
generated the handoff could not reach images.unsplash.com. Swap in the client's preferred
licensed photo anytime by replacing this file (keep the name `hero-couple.jpg`).
