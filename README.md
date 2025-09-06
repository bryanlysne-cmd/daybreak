# daybreak
nothing interesting here just boring books
# Daybreak (App 2 – Safe Dashboard)

A self-contained WordPress plugin that gives a front-end **Dashboard**, **Deals Kanban**, and **Lists** for a CRE CRM, using **admin-ajax** only (no REST nonce headaches). Designed to live alongside **Daybreak CRM Core**.

## Requirements
- WordPress 6.x
- PHP 8.1+ recommended
- Logged-in user (all ajax actions require auth)
- **Daybreak CRM Core** active (provides CPTs/taxonomies)

## Install (local)
1. Copy `wp-content/plugins/daybreak-app2-safe/` into your site.
2. Activate **Daybreak App 2 (Safe Dashboard)**.
3. Create a page at `/daybreak-app-2/` with the shortcode:
[daybreak_app2]


## Features (current)
- **Dashboard**: Scratchpad (autosave), Quick Log Activity (optional link to record), Tasks Today & Next 7, Recent Notes, Active Deals, Global Search.
- **Deals Kanban**: drag between stages, persists via `admin-ajax`.
- **Lists**: Contacts, Companies, Properties, Deals, Tasks; search; Quick Add (first four); toggle Tasks.

## Ajax endpoints (prefix `dbrk2_`)
- `dbrk2_table` — list/search items for contacts/companies/properties/deals/tasks
- `dbrk2_stages` — list pipeline stages
- `dbrk2_stage` — set deal stage
- `dbrk2_quick_add` — create contact/company/property/deal
- `dbrk2_task_add` / `dbrk2_task_toggle`
- `dbrk2_scratch_get` / `dbrk2_scratch_set`
- `dbrk2_note_add` / `dbrk2_notes_recent`

## Dev workflow
- Keep changes inside **one plugin**: `wp-content/plugins/daybreak-app2-safe/`.
- Commit small patches.
- Tag releases `vX.Y.Z` to auto-build a ZIP (see GitHub Actions below).

## Release (local)
```bash
# from repo root
bash scripts/pack.sh
# ZIP will land in dist/daybreak-app2-safe-v<version>.zip
