# Loading performance

The published site keeps the original copy, 21 gallery photographs, four review screenshots, responsive layout and continuous drawing animation.

## Changes

- AVIF responsive thumbnails with browser-native WebP fallback. The browser selects the appropriate width using `srcset` and `sizes`. Full-resolution lightbox images load only on opening.
- The hero's existing transparent mask is encoded into each responsive image, removing a separate mask request and compositing step.
- Every page photograph, including all hidden gallery thumbnails and all four reviews, is requested at startup with native eager loading. The hero has high fetch priority; remaining photographs have low priority. Gallery expansion changes visibility only. Scrolling never initiates media loading or text reveals. No placeholder GIF requests are needed.
- Russian font subsets retain the site's glyphs, Russian alphabet, Latin characters and interface symbols. Font licenses and original font files remain available.
- The cable is sampled directly from its cubic segments rather than calling SVG `getPointAtLength` 301 times per rebuild. Resize/font/load rebuilds and text-clearance updates are coalesced per animation frame. Scrolling upwards still retains already drawn segments.
- JavaScript-disabled visitors receive the same native image markup and all photographs. Browsers without AVIF support receive WebP.

## Historical measurements before switching to eager loading

The following measurements describe the earlier viewport-based loading version and do not describe the current eager-loading version.

Three cold-cache Chromium runs for each version and viewport, device pixel ratio 2, 900-pixel viewport height, 150 ms network latency, 200,000 bytes/s download throughput and 4× CPU throttling. Medians below are local lab results, not a guarantee of GitHub Pages response time. Resource weight excludes the HTML document; the local static server does not gzip CSS/JS.

| Metric | Mobile 390 px before / after | Desktop 1440 px before / after |
| --- | --- | --- |
| Largest Contentful Paint | 2.30 / 1.35 s | 2.32 / 1.36 s |
| Initial resource weight | 476 / 289 KiB | 518 / 323 KiB |
| Gallery expansion, all extra images decoded | 4.47 / 2.90 s | 9.29 / 5.09 s |
| Total long-task duration during initial loading | 2.14 / 0.17 s | 3.84 / 0.07 s |

Additional checks passed at 360, 390, 768 and 1440 pixels: text clearance around format headings and pricing, distinct format images, straight final cable segment, and no horizontal overflow. At 390 and 1440 pixels all images decoded, the gallery retained its order and 21 photographs, repeated collapse/reopen worked, both lightboxes worked, reviews remained equal in size and the drawn cable survived scrolling back upwards. The visible text matched the previous version exactly. WebP fallback and JavaScript-disabled photo access passed.

## Current eager-loading verification

At 390 and 1440 pixels, every main-page image decoded while `scrollY` remained zero, including the 17 initially hidden gallery images and all four reviews. There are no lazy images, deferred image sources or scroll-triggered text reveals. Gallery expansion and scrolling to the footer caused no additional image requests. All 21 gallery photographs remain accessible without JavaScript. Optimized media and cable calculations from the earlier release remain in place.

## Fully visible cable

The entire cable and handwritten name are visible immediately after initial layout. Scroll-driven dash offsets, curve sampling and animation listeners have been removed. The path is rebuilt only for layout changes, gallery expansion and font/image loading, retaining text clearance and responsive geometry.

## Stable gallery toggling

Gallery toggles rebuild the SVG synchronously, with an explicit height matching its viewBox. ResizeObserver updates occur before paint. The initial gallery curve and its exit bend retain their coordinates; only a straight outer-edge segment lengthens for the additional rows. Collapsing no longer forces a scroll to the button.

Verified at 360, 390, 768 and 1440 pixels across six alternating toggles: identical initial curve and signature, identical first-photo positions, unchanged viewport scroll and SVG screen transform, and no delayed scaling on the following paint. The hosting archive includes this fix.
