# WordPress.org submission (Felix uploads it)

Plugin: SamoMind AI Assistant, slug `samomind-ai-assistant`, version 0.3.3.
Submitting account: `samomind` (registered with a samomind.com address,
two-factor on). Felix's personal account `kipyegofelix` is added as a
committer after approval (step 4).

## 0. Before you upload

1. Get the zip. Run `composer build` from the plugin folder on the
   merged `main` (it packs the committed tree and leaves out everything in
   `.distignore`). The file is `build/samomind-ai-assistant-0.3.3.zip`.
   The `.wordpress-org/` images are not in it, on purpose.
2. Check it once: one top-level folder `samomind-ai-assistant/`, the plugin
   header says Version 0.3.3, `readme.txt` says Stable tag 0.3.3.
3. Make sure you can sign in as `samomind` at https://login.wordpress.org/
   (WordPress.com logins do not work there). Use the samomind.com email
   and the authenticator app.

## 1. Upload

1. Signed in as `samomind`, open
   https://wordpress.org/plugins/developers/add/
2. Choose the zip and upload it. The plugin name, slug and version come from
   the plugin header; you do not type them.
3. Submit. The slug is taken from the name: it will be
   `samomind-ai-assistant`. Do not submit twice; a second upload only
   creates a second review.

## 2. What to answer in the form

The form is short: the zip, and a tick box to confirm you have read the
plugin guidelines. Tick it. If the page offers a box for notes to the
reviewers, paste this:

> SamoMind AI Assistant is the WordPress connector for SamoMind, a hosted
> AI assistant service run by SoftwaresKe Ltd (https://samomind.com). The
> plugin needs a SamoMind account and does nothing until an administrator
> enters an ingest key and clicks Connect. Both services it contacts are
> documented under "External services" in readme.txt with the terms and
> privacy links: the SamoMind API (https://api.samomind.com) and, only if
> the administrator switches it on, the chat widget script
> (https://widget.samomind.com/widget.js), loaded with wp_enqueue_script.
> The widget is off by default. There is no tracking and no telemetry, and
> the plugin adds no credit link to the site. All code is readable PHP and
> JavaScript with no build step and no bundled libraries. The account
> `samomind` is registered with a samomind.com address, and SamoMind is our
> own brand and domain.

Check these three things match what you submit: the readme's Contributors
line is `samomind, kipyegofelix`, the licence is GPL-2.0-or-later, and
"Tested up to" is the current WordPress release (7.1 at the time of
writing; raise it when a new release comes out).

## 3. The review email

Expect an email from plugins@wordpress.org, usually within a few days to a
couple of weeks. It is a list of points to fix, or a short question. The
usual ones, and how to answer:

| They ask | Reply |
|---|---|
| Is this plugin useful without an outside service? / SaaS rules | It is the connector for a hosted service (guideline 6). Point to "External services" in readme.txt and to https://samomind.com. |
| Document the external services | Already in readme.txt: what is sent, when, and the terms and privacy links. Quote the section. |
| The widget script loads from another server | It is the service's own widget, off by default, enabled by the administrator, documented in readme.txt. It is not a CDN copy of a library. |
| Do you own the "SamoMind" name? | Yes. SamoMind is our product and samomind.com is our domain; the submitting account uses a samomind.com address. Offer to email from that address if they ask. |
| Escape, sanitise, nonces, capability checks | Every admin action checks `manage_options` and a nonce; settings go through sanitize callbacks; output is escaped. Name the file and function if they flag one, and fix it if they are right. |
| Remove a file or change a name | Do it, rebuild the zip, bump the version only if code changed. |

How to reply: answer in the same email thread (do not start a new
submission). Be short and specific, say what you changed, and attach a new
zip only if they ask for one or you changed code. If you changed code,
bump the version in the plugin header, `readme.txt` Stable tag and
CHANGELOG.md first, then run `composer build` again. Never argue about a
guideline; if you disagree, ask a question. Replies that only say "fixed"
without detail are slow to review.

## 4. After approval

The approval email gives the SVN address:
`https://plugins.svn.wordpress.org/samomind-ai-assistant`. Sign in to
SVN as `samomind`. (If the password from the account is refused, the
account profile may use a separate SVN password; set one there. I did not
test this, so check the approval email.)

```
svn co https://plugins.svn.wordpress.org/samomind-ai-assistant svn-samomind
cd svn-samomind

# trunk: the contents of the zip's samomind-ai-assistant/ folder
unzip -q /path/to/samomind-ai-assistant-0.3.3.zip -d /tmp/samomind-zip
cp -R /tmp/samomind-zip/samomind-ai-assistant/. trunk/

# assets: the .wordpress-org images, a sibling of trunk (not inside it)
mkdir -p assets
cp /path/to/samomind-ai-assistant/.wordpress-org/*.png assets/

svn add --force trunk assets
svn propset svn:mime-type image/png assets/*.png
svn status
svn ci -m "Add SamoMind AI Assistant 0.3.3"

# tag the release: the tag name is the version, trunk readme says Stable tag 0.3.3
svn cp trunk tags/0.3.3
svn ci -m "Tag 0.3.3"
```

Notes:
- `assets/` must sit next to `trunk/`, not in it. Do not copy
  `.wordpress-org/README.md`.
- The plugin page can take a few minutes to appear, and the images up to
  about 6 hours (they are served from a CDN).
- For each later release: bump the version and Stable tag, copy the new
  files into `trunk/`, `svn cp trunk tags/<version>`, commit. Keep commits
  few, each a release (guideline 14).

### Add kipyegofelix as a committer

1. Sign in at https://login.wordpress.org/ as `samomind` and open the
   plugin's admin page: https://wordpress.org/plugins/samomind-ai-assistant/advanced/
   (Plugin page, then "Advanced" at the bottom of the right-hand column).
2. In the committers box, type the exact username `kipyegofelix` and add it.
3. `kipyegofelix` must have two-factor turned on to commit; check its
   security settings, and sign in once so the account is active.
4. After that Felix can run the same `svn` commands with his own username.
   Keep `samomind` for account-level changes (owner, support, closing).
