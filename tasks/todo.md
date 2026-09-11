# Public page theming — plan for #142

## The report

A user configured branding (`primaryColor: #d8a906`, `backgroundColor: #fff8f0`),
verified it was stored correctly with `occ config:app:get`, and found the public
form still rendering Nextcloud blue in the header, buttons, accents and
background. Same result with per-form branding. They traced it to hardcoded blue
values in `css/public.css` and patched it locally.

The report is accurate. The cause is narrower than it looks, and there are three
related defects nobody has reported yet.

## What is actually wrong

### 1. Branding is applied as inline styles on four elements

`src/views/Respond.vue:318-335` sets `containerStyles` and `submitButtonStyles`,
which reach the container background, the submit button, the success checkmark
and the score percentage. That is the whole of it. Everything styled by
`css/public.css` — header, borders, links, focus rings, the datepicker — never
learns the branding exists and keeps its literal colour.

### 2. One `!important` rule overrides branding where it *is* applied

`css/public.css:545`:

```css
#formvox-public .form-actions button[type="submit"] {
  background: linear-gradient(135deg, #0082c9 0%, #0070b0 100%) !important;
}
```

This beats the inline style `submitButtonStyles` puts on that exact button. The
frontend does its job and the stylesheet discards it. This is why the reporter
sees *some* elements honour the colour and others not — it is a specificity
conflict, not a partial implementation.

### 3. `--formvox-accent` is read but never set

Four rules read `var(--formvox-accent, #0060df)` (lines 437, 475, 476, 482).
Nothing anywhere assigns `--formvox-accent`, so the fallback always wins — and
`#0060df` is not even the Nextcloud blue used elsewhere in the file. A third
blue, reachable by no configuration.

### 4. The default is a frozen literal, not the instance theme

`lib/Service/BrandingService.php:45` defaults `primaryColor` to `#0082c9`. An
admin who themes their Nextcloud orange and never touches FormVox branding still
gets Nextcloud-default blue on every public form. The default should be the
instance's own primary colour.

### 5. Branding breaks dark mode

`containerStyles` applies the chosen background unconditionally. In dark mode the
reporter's `#fff8f0` cream is painted onto the card while `--formvox-text-primary`
stays `#e0e0e0` light grey — near-unreadable. There is no configurable text
colour (`BrandingService` offers only `primaryColor`, `backgroundColor`,
`fontFamily`), so a readable foreground has to be derived, not configured.

## Why the public page is the odd one out

Measured across FormVox's own stylesheets:

| Stylesheet | NC theme tokens | Hardcoded hex |
|---|---:|---:|
| `editor.css` | 13 | 0 |
| `results.css` | 10 | 0 |
| `main.css` | 12 | 1 |
| `respond.css` | 23 | 1 |
| **`public.css`** | **0** | **82** |

Every internal stylesheet follows the Nextcloud theme. Only the public one does
not — 82 hardcoded colours, 55 `!important` rules, zero `--color-*` references.
The public page loads `public.css` exclusively (`PublicController.php:107,122,362,747,786`).

Side finding: `css/respond.css` is loaded by no controller and referenced nowhere
in `src/`. It is dead code, shipped in every release tarball since January, and
is plausibly the theme-following stylesheet the public page was meant to use.

### The same pattern across the VoxCloud apps

The house pattern is consume-only: read NC theme tokens directly, hex permitted
only as the fallback argument of `var(--token, #fallback)`. Where app-level
tokens exist they are component-scoped and defined *from* NC tokens — IntraVox's
`--iv-filter-*`, RoomVox's `--fc-*` bridging FullCalendar onto NC tokens. No app
publishes a global token layer.

`BrandingController.php` exists only in FormVox. IntraVox does let users pick
widget background colours, but stores them as NC **token strings**
(`'var(--color-primary-element)'`), not hex — so the choice still tracks the
instance theme. That is a useful precedent.

IntraVox has the identical bug in its two public PHP templates
(`templates/public-password.php`, `public-not-found.php`): hardcoded
`#f5f5f5`/`#333`/`#0082c9` while the sibling `PublicPageView.vue` themes
correctly. In both apps the anonymous-facing pages are the only ones ignoring the
theme — exactly backwards, since those are what outsiders see.

### What comparable products do

Two camps, and the split is not arbitrary.

**SaaS form products** (Microsoft Forms, Google Forms, Tally, Jotform) treat
branding as a per-form authoring concern. Microsoft Forms gives a per-form hex
colour and background image, with **no** org-level theme and no tenant
inheritance; Microsoft's own guidance for reusing a logo is "duplicate a branded
form". Typeform's Brand Kits are the most admin-flavoured implementation found,
and even there a kit is a shared asset library still applied per form.

The reason is structural: on `forms.office.com` there is no host identity worth
showing a respondent. The host is Microsoft, not the author's employer, so the
author must supply identity per form.

**Self-hosted Nextcloud** inverts that. The Theming app's primary colour, logo
and name already *are* the organisation's identity, applied consistently to
public file shares, the login page, Talk and Deck. Upstream `nextcloud/forms`
5.4.0 inherits the server theme wholesale and has **zero** per-form branding: no
styling columns in `forms_v2_forms`, no branding getters on the `Form` entity,
and a `css/public.css` that is seven lines. Nobody has filed for per-form
branding there.

So for a Nextcloud forms app, theme inheritance is the expected behaviour and
per-form branding is the exception. FormVox's branding system is a genuine
differentiator worth keeping — but it should sit *on top of* the instance theme
rather than replacing it with frozen defaults.

## Proposal

**The theme is the floor; branding is a thin accent layer on top.**

Concretely: keep the branding feature and the page builder, but make the
unbranded state equal the instance theme, and make branding work by assigning
the existing `--formvox-*` tokens rather than by painting four elements inline.

### Step 1 — define the token layer from NC tokens

`css/public.css:6-16` already declares a well-named token set
(`--formvox-bg-primary`, `--formvox-text-primary`, `--formvox-border`,
`--formvox-page-bg`, …). Redefine those in terms of `--color-*` with the current
literals kept as fallbacks:

```css
:root {
  --formvox-bg-primary: var(--color-main-background, #ffffff);
  --formvox-text-primary: var(--color-main-text, #1a1a1a);
  --formvox-border: var(--color-border, #e0e0e0);
  --formvox-accent: var(--color-primary-element, #0082c9);
  /* … */
}
```

The `emit_css_loading_tags` call in core's `layout.public.php` loads the themed
stylesheet on public pages, so `--color-*` is available to anonymous visitors —
verified in the server source, not assumed.

This also removes the hand-rolled `prefers-color-scheme` and `[data-themes*=dark]`
blocks at lines 19-45: NC's own tokens already carry both modes, which is the
cross-cutting smell flagged across the other apps too.

### Step 2 — apply branding by assigning tokens, not inline styles

One place sets `--formvox-accent` and `--formvox-page-bg` from
`branding.globalStyles` onto the root element. Everything in `public.css` then
follows automatically, and `containerStyles`/`submitButtonStyles` in
`Respond.vue` can go.

### Step 3 — replace the literal colours

Substitute the 55 hardcoded colours in rule bodies with the tokens. The eight
`#0082c9` occurrences become `var(--formvox-accent)`; the datepicker block at
lines 718-810, which hand-codes a light and a dark palette, collapses onto the
tokens.

### Step 4 — remove the `!important` rules that fight the branding

Starting with line 545. Most of the 55 exist to out-specify Nextcloud's public
layout; the ones overriding our own branding are bugs.

### Step 5 — derive a readable foreground

When a background colour is set, compute the text colour from its luminance
rather than assuming dark-on-light. IntraVox's `src/utils/colorUtils.js` already
does this classification and documents the contrast measurements; the same
approach applies here.

### Step 6 — default to the instance theme

`BrandingService::DEFAULT_GLOBAL_STYLES` should express "no override" rather than
`#0082c9`, so an unbranded instance inherits its own primary colour.

## Open question for review

**Should the free background-colour picker stay?**

It is the source of most of the difficulty: contrast, dark mode, and the ability
for an admin to make a page that does not look like the host organisation.
Accent colour plus logo covers most of the need at a fraction of the risk, and
matches the IntraVox precedent of constraining choices to safe values.

Removing it is a behaviour change for anyone already using it, so it is not mine
to decide — hence this PR rather than a patch. The steps above are written to
work either way: if the picker stays, step 5 is required; if it goes, step 5
becomes unnecessary and step 3 gets simpler.

## Scope

| | |
|---|---|
| Files | `css/public.css`, `src/views/Respond.vue`, `lib/Service/BrandingService.php`, possibly a new `src/utils/contrast.js` |
| Data model | no change — stored branding keeps its shape |
| Migration | none |
| Translation strings | none |
| Behaviour change | unbranded instances start following their own theme instead of showing Nextcloud-default blue |
| Risk | moderate — touches every visual surface of the public page; needs visual checking in light and dark, branded and unbranded |

## Not in this PR

- `css/respond.css` is dead code and should be deleted, but that is unrelated
  cleanup and belongs in its own change.
- IntraVox's two public templates have the same defect. Separate repository,
  separate issue.
- MetaVox `css/admin.css:1-13` redefines *core* NC tokens at `:root` with literal
  hex, which un-themes the instance wherever that stylesheet loads. Highest-value
  fix of the three, unrelated to this one.

## Checklist

- [ ] Step 1 — token layer defined from NC tokens
- [ ] Step 2 — branding assigns tokens
- [ ] Step 3 — literal colours replaced
- [ ] Step 4 — conflicting `!important` rules removed
- [ ] Step 5 — derived foreground (only if the background picker stays)
- [ ] Step 6 — default follows the instance theme
- [ ] Verified: unbranded, light and dark
- [ ] Verified: branded, light and dark
- [ ] Verified: per-form branding overriding admin defaults
- [ ] Verified on a themed instance (non-default primary colour)
