# JMAP regression checks

Run from the repository root with PHP 8.2 or newer:

```sh
php tools/jmap-tests/run.php
```

No credentials, live Stalwart server, Composer packages or PHP test framework
are needed. Tests use real Z-Push sync objects and a fake JMAP transport.
Fixtures are synthetic and follow the Stalwart 1.1.0 JSContact/JSCalendar output.

Covered:

- Contact photo blob downloads, response-account routing and legacy data URIs.
- Photo replacement/deletion without stale blobs or loss of other media.
- Photo change detection when the media count and timestamp stay unchanged.
- Singular `recurrenceRule`, monthly ordinals, local recurrence end times,
  existing series timezones and floating all-day dates.
- `calendarAddress`, `organizerCalendarAddress`, participant roles and stable
  participant IDs/metadata on edits.
- `keywords` as ActiveSync category arrays, including labels with spaces,
  explicit removal and preservation of separate JSCalendar concepts.
- Calendar update requests, change fingerprints and property selection.
- Mail fallback decoding for plain/HTML preferences, legacy charsets, transfer
  encodings, inline text and exclusion of text/attached-message attachments.
- Raw MIME preservation for signatures, encrypted messages and invitations;
  reconstruction of unsafe unsigned MIME only when MIME was requested.
- UTF-8-safe text truncation, untruncated MIME, empty bodies, failed downloads,
  truncated JMAP values, and preservation of HTML entities.

These checks do not replace a live device/server test. After deployment, check
a contact photo download/replacement and a repeating meeting round trip on a
test calendar. Existing server-side recurrence overrides are preserved on
updates; this change does not implement full ActiveSync exception conversion.

For the MIME-loop fix, verify that the affected mail folder's Sync counter
advances and its pending change count decreases after deployment. The new
`decoded MIME fallback` log includes body preferences and byte counts, without
logging message contents. Opaque encrypted messages requested as text have an
empty preview; the original attachment remains available. Successful offline
tests alone do not establish why a particular device rejected a real message.
