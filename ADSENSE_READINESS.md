# AdSense readiness

This document describes **technical and structural preparation** against Google’s published AdSense requirements.

It does **not** mean the site is approved.

**AdSense approval remains Google’s decision.**

The website has been technically and structurally prepared against Google's published requirements, but AdSense approval remains Google's decision.

## What has been implemented

- Configuration-only publisher settings (`ADSENSE_ENABLED`, `ADSENSE_CLIENT_ID`, `ADSENSE_AUTO_ADS`, database publishing settings)
- `/ads.txt` served only when a syntactically valid `ca-pub-` ID is stored. Until then the route is 404 so a fake seller line is not published
- Page-level `ads_enabled` and `child_directed` flags on public posts
- Ads excluded from portal, staff, parent, and student desks; login; APIs; feeds; sitemaps; authenticated sessions
- Public/private site separation
- Privacy Policy, Terms, contact, and about pages
- Cookie / consent banner that is not hidden and does not pretend optional cookies are required
- Consent required before ads or analytics scripts load
- Unique titles, descriptions, canonicals, robots, sitemap, and RSS on the public journal
- Empty hubs and search results stay `noindex`
- `php artisan site:audit` reports an **AdSense readiness check**, never “AdSense approved”

## Credentials now stored

A real publisher ID has been placed in environment configuration (`ADSENSE_CLIENT_ID`). It is not hardcoded in templates.

The school-supplied seller line is stored as `ADSENSE_ADS_TXT` and published at `/ads.txt` and `public/ads.txt`:

```
google.com, pub-4828740366189357, DIRECT, f08c47fec0942fa0
```

That file will be empty of a seller line (404 from the Laravel route) in tests and any environment without this ID. Publishing the line is not the same as Google confirming the file.

This does **not** mean AdSense is approved. Approval remains Google's decision.

## What still requires Google or the school

- Site verification token, if Google asks for one (`ADSENSE_VERIFICATION_CODE`)
- A measurement ID (`G-L1TL37XYN7`) is stored as `ANALYTICS_MEASUREMENT_ID`. The gtag script loads only after analytics consent. No names, emails, or phone numbers are sent.
- Cookie consent from the visitor before the ad script loads
- Google’s review of the live site and ads.txt
- Enough original public content for a genuine site

Never invent a second publisher ID. Use only the ID Google issued.

## What still requires human content

- Original articles, guides, and parent resources written and reviewed by the school
- Enough useful pages in each hub before that hub is indexed
- Honest about / admissions / contact copy (no invented awards, results, or rankings)
- Real downloadable PDFs only when the house has produced them
- Author biographies that do not invent qualifications

Google expects a real site with useful original pages. Architecture alone is not a site.

## What still requires Google review

- The AdSense application itself
- Policy review of content, navigation, and ads.txt
- Any later product review if the school changes the public site

No one here can promise the outcome of that review.

## What the school must do before applying

1. Publish a body of original, useful public writing (not thin doorway pages)
2. Keep privacy, terms, about, and contact accurate
3. Place the real publisher ID in configuration only when Google issues it
4. Enable AdSense in publishing settings only after that ID exists
5. Review Google’s current rules on **child-directed** content and turn ads off on pages that should not carry personalized advertising
6. Confirm ads never appear on desks, fees, results, or application forms
7. Run `php artisan site:audit` and fix errors
8. Apply in the AdSense interface — and wait for Google

## Correct public language

Use:

> The website has been technically and structurally prepared against Google's published requirements, but AdSense approval remains Google's decision.

Do not say “Google will approve this” or “this guarantees AdSense approval.”
