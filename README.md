# Sebo Attribution

Records where each lead came from and saves it with every Gravity Forms entry:
channel, UTM tags, Google/Microsoft/Facebook click IDs, referrer, landing page,
and the GA client ID. No license, no setup per form, and nothing leaves the
site.

## What it does

1. **`assets/capture.js`** runs on every page (10 KB, about 4 KB gzipped; no jQuery; deferred).
   When a page view carries a signal (UTM tags, a click ID, or an external
   referrer) it records a *touch* in a first-party cookie, `sebo_attr`.
   Nothing leaves the site.
2. **On form submission**, PHP reads that cookie and:
   - saves every value as **entry meta** on every entry of every form, with no
     setup per form;
   - fills any **hidden field** whose parameter name is `sebo_<key>`.
3. **Entries** show an **Attribution** box in the sidebar. Each value is also
   available as an Entries list column, a CSV export column, and an entry search
   filter, labeled `Attribution: gclid` and so on.

### First touch and last touch

| Recorded as | Meaning |
|---|---|
| `channel`, `utm_*`, `landing_page`, `referrer` | The most recent visit that was **not Direct**. Someone who clicks an ad and later types the address in is still credited to the ad. This matches GA4 and Google Ads ("last non-direct click"). |
| `first_*`, `first_visit` | The first visit we recorded. Never overwritten. |
| `gclid`, `gbraid`, `wbraid`, `msclkid`, `fbclid` | The latest value of each, kept separately so a later organic visit does not lose the GCLID that offline conversion import needs. |

Values fill even without UTM tags. A Google Ads click with auto-tagging and no
UTMs records `google / cpc / Paid Search`, not a generic "Search Engine", so
paid leads are not undercounted.

### Channel labels

These are modeled on GA4's default channel groups, so they match what the team
sees in Analytics: `Paid Search`, `Organic Search`, `Paid Social`,
`Organic Social`, `Email`, `Referral`, `Display`, `Affiliates`, `Paid Other`,
`Other Campaign`, `Direct`.

`channel` is last non-direct; first touch is `first_channel`. A CRM report or
Zap that filters on another tool's channel wording needs updating when a site
moves over.

## Hidden fields

You only need them where a notification, CRM feed or Zapier must see the values
inside the form. Entry meta is recorded either way.

- **Label**: the plain key: `gclid`, `utm_source`, `channel`…
- **Parameter name**: `sebo_gclid`, `sebo_utm_source`… The prefix is
  deliberate. Gravity Forms also fills dynamically populated fields from the
  page URL, so a field named plain `gclid` would take today's URL over the
  stored ad click.
- Fields are matched by parameter name, so renaming a label is safe.
- Values are written at submission, never into the page HTML. Kinsta caches
  pages, and a value rendered into cached HTML would be served to everyone.

**Standard set:** `channel, utm_source, utm_medium, utm_campaign, gclid,
landing_page`. **Full set:** all 20 keys. Pick the default under
**Forms → Attribution**.

### Ways to add them

All of these are safe to repeat; a form that already has a field for a key is
skipped.

| Where | How |
|---|---|
| New forms | Automatic (setting on by default). Covers blank forms, templates, imports and `GFAPI::add_form()`. |
| One form | Form → Settings → **Attribution** tab → *Add standard tracking fields* / *Add full set*. |
| Several forms | Forms list → tick forms → Bulk actions → **Add tracking fields**. |
| All forms | Forms → Attribution → *Add tracking fields to all forms*. |
| Terminal | `scripts/wp sebo-attribution add-fields --all` (see below). |

New fields go at the end of the form. In the editor, drag them wherever you
like; hidden fields render nothing on the page.

## Rolling out

### New framework sites

`bootstrap.sh` deploys and activates it after Gravity Forms, so every form built
afterward, including the ones from `create-form.php`, gets its fields.

### Sites in progress and launched sites

From that site's project folder, with the current framework's `plugins-src/` and
`scripts/` copied in:

```bash
bash scripts/deploy-plugin.sh sebo-attribution --activate
scripts/wp sebo-attribution status
scripts/wp sebo-attribution add-fields --all --dry-run
scripts/wp sebo-attribution add-fields --all
```

Later updates are the same `deploy-plugin.sh` run; `--activate` is only needed
the first time.

### Sites that already have tracking fields

`add-fields` **reuses** hidden fields a site already has, labeled with a key
such as "gclid" or "UTM Source", instead of adding duplicates. It only sets
their parameter names. Labels, field IDs, past entries, notifications and CRM
mappings that point at those fields keep working. Check the "reuse" lines with
`--dry-run` first.

## Settings

**Forms → Attribution**

| Setting | Default | Notes |
|---|---|---|
| Add tracking fields to every new form | on | |
| Fields to add | Standard | Or the full set. |
| Wait for marketing-cookie consent | off | Turn on for sites with a cookie banner. Uses the WP Consent API (Complianz, CookieYes, Cookiebot's WP plugin and others), or set `window.seboAttributionConsent = true` / dispatch a `sebo-attribution-consent` event. |
| Remember visits for | 90 days | Google Ads accepts offline conversions for 90 days after a click. |

## WP-CLI

```bash
wp sebo-attribution status                         # forms, their tracking fields, settings
wp sebo-attribution add-fields --all [--dry-run]   # every form
wp sebo-attribution add-fields 3 7 --set=full      # specific forms, full set
```

## Updates

Sites update from this repository's GitHub releases, like any plugin. No token
or setup is needed (the repository is public).

To release: bump `Version:` and `SEBO_ATTR_VERSION` in `sebo-attribution.php`,
push to `main`, then publish a GitHub release tagged `vX.Y.Z` with
`sebo-attribution.zip` attached (`bash bin/build-zip.sh`), which is what the
Sebo Plugins page in the hub offers for a first install.

## Limits worth knowing

- **Safari** caps cookies written by JavaScript at 7 days. After any change
  the script asks `POST /wp-json/sebo-attribution/v1/refresh` to re-issue the
  same cookie from the server, which gets the full lifetime. POSTs are never
  served from Kinsta's cache.
- **`gclid` also appears on Google Display, YouTube and Performance Max clicks.**
  Without UTM tags those are recorded as Paid Search; with
  `utm_medium=display` they are Display. This is the same limit GA4 has without
  a linked Ads account.
- **`fbclid` is not proof of an ad.** Facebook adds it to organic link clicks
  too, so on its own it records Organic Social. Paid Social needs
  `utm_medium=paid_social` (or `cpc`, `paid…`) on the ad.
- **Cookie-blocking browsers and declined consent** produce entries with no
  attribution. The Attribution box says so rather than showing blanks.
- **Cross-domain journeys** (a separate booking subdomain) are not stitched.
  The cookie is set for the exact host.
- **Values are visitor-controlled.** They are sanitized, length-capped, and
  prefixed with `'` if they start with `= + - @`, so a crafted UTM cannot run as
  a formula when the CSV is opened in Excel.

## Tests

```bash
node tests/capture.test.js
```

31 scenarios: channel classification, first and last touch across visit
sequences, click-ID retention, consent gating, and length caps. The PHP side
was verified against Gravity Forms 3.0.2 on WordPress 7.1 and PHP 8.3. Neither
`deploy-plugin.sh` nor the release zip includes `tests/`.
