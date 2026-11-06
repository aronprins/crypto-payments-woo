# AGENTS.md

Notes for AI coding agents (and humans) working on Crypto Payments for WooCommerce. Read this before changing the updater, the release workflow or version numbers. This file is listed in `.distignore`, so it stays out of release zips.

## What this is

A WooCommerce payment gateway (PHP 7.4+, WordPress 5.8+) that takes crypto payments straight to the store owner's wallets. It's distributed through **GitHub releases only**, not WordPress.org, and updates itself from those releases.

There is no build step: the release zip is the repo minus everything in `.distignore`.

## Releases

### Naming

**A release is named exactly after its tag: tag `v1.3.0`, release name `v1.3.0`.** Don't add the plugin name or anything else. The Release workflow sets this, and any release made or edited by hand must follow it too. Use a plain tag (`git tag v1.3.0`), not an annotated one with its own title.

### Steps

1. Set the same version in the `Version:` header and `CPW_VERSION` in `crypto-payments-woo.php`, and in `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` entry at the top of `== Changelog ==` in `readme.txt`. It becomes the release notes.
3. Commit to `main`, then tag and push: `git tag vx.y.z && git push origin vx.y.z`.
4. Check that the release has both `crypto-payments-woo.zip` and `update.json`, and that `https://github.com/aronprins/crypto-payments-woo/releases/latest/download/update.json` shows the new version.

The workflow fails if the versions don't match the tag or the changelog entry is missing. A tag with a hyphen (`v1.3.0-beta.1`) becomes a pre-release, which sites ignore; its version numbers and changelog entry must match the part before the hyphen.

## The updater contract: don't break existing installs

Every installed copy carries its own updater, so these must stay the same or sites stop updating:

- The repo owner and name, `CPW_Updater::REPO` and the `Update URI` header.
- The asset names `crypto-payments-woo.zip` and `update.json`, and the zip's top folder `crypto-payments-woo/` containing `crypto-payments-woo.php`.
- The existing `update.json` fields. Only add fields; never rename or remove them.

A release without `crypto-payments-woo.zip` is never offered. Don't add GitHub's source zip as a fallback.

## Plugin Check

Check what ships, not the repo: run Plugin Check with `--exclude-directories=.github`. The repo's dev files (`.github`, `.gitignore`, `.distignore`) aren't in the zip.

Two findings are expected and stay, because they are the update feature itself and only matter for plugins hosted on WordPress.org:

- `plugin_updater_detected` (the `Update URI` header)
- `update_modification_detected` (the automatic updates switch in the gateway settings)

## Local development

Don't click Update for this plugin on a site where the plugin folder is a git checkout. WordPress replaces the whole folder, including `.git`.
