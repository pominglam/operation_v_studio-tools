# Shopify static content pages — workflow & About Operation V Studio (handoff)

**Audience:** Operation V Studio storefront maintainers · **Shop:** operationvstudio.com

**Content hierarchy (pages, Getting started nav, mega menu CTA slots):** [../requirements/storefront-content-hierarchy.md](../requirements/storefront-content-hierarchy.md)

**Important:** Operations in this repository do **not** deploy or preview the Shopify theme. All theme changes happen in Shopify (duplicate unpublished theme recommended) or a dedicated theme codebase.

---

## 1. Non-negotiable workflow (publishing gates)

| Rule | Practice |
|------|----------|
| No live/automatic publish | Do **not** push this work to the **published** theme. Use **Duplicate theme** and work on the copy, or Shopify CLI pointing at an **unpublished** theme. |
| Visual preview required before sign-off | Use **Customize** on the **draft theme** → open the page preview, or storefront URL `?preview_theme_id=THEME_ID` (see §6). Do not publish the duplicate until reviewed. |
| No production edits without approval | Do not overwrite the production theme ZIP or click **Publish** on the draft unless explicitly approved. |
| Scope isolation | Prefer a **custom page template** that only affects this page assigned to **About**. Do not alter `index.json`, product, collection, cart, checkout, or global navigation unless separately requested. |
| Lightweight JS | Prefer CSS + native HTML; no new external libraries unless approved. |

**Agent / contractor note:** If you cannot open Shopify theme preview (no admin access, no `preview_theme_id`), **stop** and report that preview was not validated—do **not** claim layout is verified.

---

## 2. Design language (constraints)

1. Match existing Operation V typography, spacing, containers, buttons, and colors (**reuse theme sections/snippets** identified in Theme Editor — section names vary by theme).
2. Do **not** introduce a parallel design system or global CSS that affects unrelated templates.
3. Premium, calm, builder-focused tone; intentional negative space consistent with existing pages.
4. Semantic headings: one logical `h1` for the page title; subsection `h2`/`h3` in order—no skips for visuals.

---

## 3. Maintainability strategy (recommended)

### Preferred: Online Store 2.0 **page JSON template**

- File in theme repo: **`templates/page.about-operation-v-studio.json`** (exact filename is optional but stable).
- Compose the page from **existing** sections (rich text, image banner, multicolumn, custom sections your theme already ships).
- Prefer **editable settings** (`heading`, `text`, `button_label`, `button_link`) over hardcoded prose in Liquid, so merchants can revise copy without code deploys—**except** while you stabilize the structure.
- Duplicate the same JSON pattern later for **`page.contact`**-style layouts (Contact, FAQ, Events, Studio Services).

### Fallback: Shopify **Page body** (`page.content`)

Use the default `page.json` template and put structured HTML/MarkDown in the Admin page body only if JSON templates cannot be deployed yet—it is weaker for repeatable section spacing but edits without theme deploy.

---

## 4. SEO & accessibility checklist (minimal)

| Item | Implementation |
|------|----------------|
| Page title | Shopify Page title: **`About Operation V Studio`** |
| Meta description | Set in Shopify Page “Search engine listing” (snippet ~150 chars when you finalize copy—placeholder below). |
| URL | **`/pages/about-operation-v-studio`** (handle **`about-operation-v-studio`**) |
| Semantics | `main`, headings in order, lists where appropriate |
| Buttons vs links | Use theme button styles for CTAs; **descriptive labels** (“Browse model kits”, not “Click here”). |
| Contrast & responsive | Rely on existing theme breakpoints; QA on real devices |

**Meta description placeholder (edit before publish):**

> Operation V Studio is a Montreal hobby store and studio for Gundam builders—kits, communal build space, demos, painting help, and a welcoming builder community.

---

## 5. Page record (Shopify Admin → Online Store → Pages → Add page)

| Field | Value |
|-------|--------|
| Title | About Operation V Studio |
| Visibility | Hidden (or unpublished) until review—do **not** add to mega-menu menus until approved |
| URL and handle | `about-operation-v-studio` → `/pages/about-operation-v-studio` |
| Theme template | After theme work: **`page.about-operation-v-studio`** (or name you configured in §3) |

**CTA URLs (adjust to match your storefront routes):**

| Label | Suggested destination | Notes |
|-------|----------------------|--------|
| Browse model kits | `/collections/all` | Change if your flagship collection has a nicer URL (e.g. `/collections/model-kits`). |
| Visit the studio | External map search for **852 Bd Décarie, Montréal, QC H4L 3L9** or `/pages/contact` when that page exists |
| New to Gunpla? Start here | `/pages/new-to-gunpla` | Create stub page later or temporarily link to beginner collection/policy page |

---

## 6. How to preview the draft **without** publishing (required reading)

### A. Duplicate theme first

**Shopify Admin → Online Store → Themes → ⋯ → Duplicate**  
Work only on that duplicate theme.

Copy the duplicated theme’s **`theme_id`** (visible in Customize URL query string or Shopify Admin URLs).

### B. Customize from Admin

Open **Customize** on the duplicated theme → in the dropdown, navigate to **Pages → About Operation V Studio** (create the blank page first with the correct template assignment if Shopify requires it—or start from Homepage and assign template once file exists).

### C. Storefront preview link (sharing)

After the page exists and is wired to your draft theme:

```
https://operationvstudio.com/pages/about-operation-v-studio?preview_theme_id=<THEME_ID>
```

Replace `<THEME_ID>` with the numeric ID of your **duplicate** theme only.

Shopify docs also support theme preview URLs from Theme Editor (**Preview** buttons).

### D. Shopify CLI local preview (alternative)

Developers pull the unpublished theme locally:

```text
shopify theme dev --environment <configured-env>
```

Point to the **duplicate** theme’s ID in `shopify.theme.toml`; preview opens a secure tunnel—the live published theme stays untouched.

---

## 7. Suggested section outline (mapping to placeholders)

Reuse your theme’s **actual** section `type` values (inspect `templates/*.json` in the theme codebase).

| Narrative section | Shopify concept |
|-------------------|-----------------|
| Hero | Image banner / hero / slideshow with subdued imagery (optional)—**headline**: page title variant |
| Short intro | Rich text section |
| More than a store | Rich text or two-column rich text |
| For every builder level | Rich text or multicolumn icons (if theme has columns) |
| What you’ll find here | Bullet list rich text |
| Mission statement | Rich text (“Our mission…” + italic tagline optional) |
| Visit us / CTA | Banner or rich-text + duplicate button group (browse / directions / newbie) |

Implement CTAs via **three button settings** referencing theme button classes for consistency.

---

## 8. Placeholder marketing copy (source of truth for first publish)

Paste into sections or HTML body as structured below.

### Hero

- **Headline:** About Operation V Studio  
- Optional subheading: Built by builders, for builders, with builders  

### Intro (short)

Operation V Studio is a hobby store and builder-focused studio in Montreal, created by builders, for builders, with builders.

### More than a store

At our core, we are passionate about model kits, especially Gundam and Gunpla, but the studio was designed to be more than a retail store. We wanted to create a space where builders can discover new kits, sit down and build, learn new techniques, meet other hobbyists, and grow at their own pace.

### For every builder level

Whether someone is opening their very first kit, returning to the hobby after many years, or already deep into custom builds, painting, panel lining, weathering, and competitions, Operation V Studio is meant to be a welcoming place to keep evolving.

### What you’ll find here

Our space includes building tables, shared tools, guidance from experienced builders, product demos, community build nights, and airbrush booth access for builders who want to explore painting and customization.

We believe model building is not just about finishing a kit. It is about focus, creativity, patience, problem solving, and the feeling of bringing something to life with your own hands.

### Mission

**Our mission is simple:**

To help builders evolve beyond their imagination — offering the tools, knowledge, and inspiration to achieve what once felt impossible.

---

**Operation V Studio**  
Built by builders, for builders, with builders.

### Visit us

**852 Bd Décarie**  
**Montréal, QC H4L 3L9**

Online: **[operationvstudio.com](https://operationvstudio.com/)**

Buttons:

1. **Browse model kits** → `/collections/all` (adjust)  
2. **Visit the studio** → map or contact URL  
3. **New to Gunpla? Start here** → `/pages/new-to-gunpla` (create when ready)

---

## 9. Skeleton `page.about-operation-v-studio.json` (replace `type` with your theme sections)

⚠️ **Placeholder only.** Inspect your theme’s `sections/` schema (e.g. `rich-text`, `image-banner`). Types differ between Horizon, Sense, Dawn, and custom stacks.

```json
{
  "sections": {
    "about_hero": {
      "type": "REPLACE_WITH_THEME_IMAGE_OR_HERO_SECTION",
      "settings": {
        "image_overlay_opacity": 30,
        "heading": "About Operation V Studio",
        "subheading": "Built by builders, for builders, with builders.",
        "button_label_primary": "",
        "button_link_primary": ""
      }
    },
    "about_intro": {
      "type": "REPLACE_WITH_THEME_RICH_TEXT_SECTION",
      "settings": {
        "heading": "",
        "text": "<p>Operation V Studio is a hobby store and builder-focused studio in Montreal, created by builders, for builders, with builders.</p>"
      }
    },
    "about_more_than_store": {
      "type": "REPLACE_WITH_THEME_RICH_TEXT_SECTION",
      "settings": {
        "heading": "More than a retail store",
        "text": "<p>… paste middle sections from §8 …</p>"
      }
    },
    "about_cta_band": {
      "type": "REPLACE_WITH_THEME_RICH_TEXT_SECTION",
      "settings": {
        "heading": "Plan your visit",
        "button_label_primary": "Browse model kits",
        "button_link_primary": "/collections/all",
        "button_label_secondary": "Visit the studio",
        "button_link_secondary": "https://www.google.com/maps/search/?api=1&query=852+Bd+D%C3%A9carie%2C+Montr%C3%A9al%2C+QC+H4L+3L9",
        "button_label_tertiary": "New to Gunpla? Start here",
        "button_link_tertiary": "/pages/new-to-gunpla"
      }
    }
  },
  "order": [
    "about_hero",
    "about_intro",
    "about_more_than_store",
    "about_cta_band"
  ]
}
```

**Note:** Few themes expose three-buttons in one block—often you combine **multicolumn**, **featured collection**, plus **richtext/button** sections. Adapt to what already exists rather than forcing new Liquid.

---

## 10. What you must approve before anything goes live

- [ ] Visual QA on duplicate theme (desktop + mobile): header/footer spacing, typography, horizontal scroll.  
- [ ] All CTA links resolve (collections, Maps, newbie page stub).  
- [ ] Navigation: **only after approval**, add `/pages/about-operation-v-studio` to menus/ffooter.  
- [ ] Confirm meta description finalized.  
- [ ] Explicit **Publish** on theme or publish page visibility—not automatic from this Laravel repository.

---

## 11. Why this markdown lives in **pricing-tool**

The internal pricing/catalog app integrates with Shopify (OAuth, ERP mirrors) but does **not** host `.liquid`/`.json` theme files. This document is the **handoff artifact** plus ongoing rules until a dedicated theme repo is linked—or until theme files are added under `/docs`-adjacent tooling.

**Files touched in-repo for this initiative:** listed in engineering final report accompanying the implementing agent’s checklist.
