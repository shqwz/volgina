# Text reviews and review submissions

The four existing cards retain their original horizontal layout and mobile swipe controls. Each contains text transcribed from its original screenshot and a link to open that screenshot in the lightbox. Original review screenshots have no next/previous or swipe navigation; the close button sits at the right edge of the viewport. Gallery navigation remains available. The cards use subtle paper grain, powder-pink accents and one consistent body typeface throughout each review. Gallery images and all other page copy are unchanged.

An expandable first card, before the four published reviews, contains the form. Native details/summary controls support keyboard use and keep entered values when collapsed. Mobile carousel navigation includes this card, and the cable follows all five cards. The form accepts a name, optional event description and review text. `submit-review.php` validates submissions and stores them with `status: pending`; it does not publish them. Moderation will be added with the planned admin interface.

## Hosting

Deploy the hosting ZIP directly into `lazurin/public_html/volgina/`. PHP 7.4+ is required for form submission; PHP 8 is recommended. GitHub Pages can display the layout but cannot execute the submission endpoint.

The default private storage is `lazurin/volgina-review-data/reviews.json`, outside `public_html`. The PHP account must be able to create and write this folder. `VOLGINA_REVIEW_DATA_DIR` can override its location if the hosting configuration needs it; keep it outside the public document root.

A separate lock file and atomic replacement protect saved reviews during writes. The handler validates Unicode and field lengths, rejects malformed array values and foreign browser origins, includes a honeypot and limits submissions from one client to three per hour. It returns JSON for the JavaScript form and an HTML response for ordinary form submissions. Client validation failures and server/network errors retain entered text; success clears the form and confirms pending moderation. The future admin interface must escape stored user text when rendering it.

## Verification

Verified with PHP 8.4.24 and Chromium:

- At 390, 768 and 1440 pixels: all four text cards and original screenshots; desktop cards remain in one equal-height row; mobile swipe layout and form have no horizontal overflow.
- A browser submission persisted privately as pending, reset the form and left the published review count at four.
- The handler rejected short reviews, malformed array fields and foreign origins; honeypot submissions were discarded; simultaneous requests were saved; the rate limit rejected further submissions without altering stored records. Private storage was inaccessible through the site's public URL.
- At 360, 390, 768 and 1440 pixels: six alternating gallery toggles retained the SVG transform, initial curve, first-photo positions and viewport scroll.
- The hosting ZIP includes the PHP handler and original review screenshots; it contains no submitted review data.
