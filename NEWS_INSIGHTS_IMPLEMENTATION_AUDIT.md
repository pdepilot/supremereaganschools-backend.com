# News & Insights Implementation Audit

Supreme Reagan Schools — Laravel 13 school house.  
Audit date: 29 August 2026.  
No production data is changed by this document.

## Existing architecture

The application is a session-authenticated school ERP plus a static public website.

- Public HTML lives in `resources/frontend/public/` and is served by `FrontendController` through `FrontendPage` and `FrontendLinker`.
- Admin / staff / parent / student desks are HTML shells in `resources/frontend/` that call `/api/v1/*` with cookies and CSRF.
- JSON envelope is `App\Support\ApiResponse`.
- There is no Fortify, Breeze, or Sanctum. Do not add them.
- Internal **Announcements** (`announcements` table) are portal notices for parents, staff, and pupils. They are not a public publishing platform.

## Existing tables

No `posts`, `post_categories`, `post_tags`, sitemap, ads, cookies, or privacy tables exist.

The only content-like table is `announcements` (internal, audience-scoped). It must stay untouched.

`school_settings` holds school identity, motto, address, phone, email, logo, office hours. Reuse it for publisher name, logo, and contact on public articles.

`documents` is a private polymorphic file store. Public article images should use the **public** disk (`storage/app/public/news`) so they can be crawled. Do not mix article images into private pupil documents.

## Existing models

No `Post`, `Article`, or public `Event` model exists.

Reuse: `User` (editorial author via staff/admin identity), `SchoolSetting`, `Role` / `RoleSlug`, `StaffProfile` (display name / photo when present).

Do **not** use `student_id` as author identity.

## Existing authentication

Session guard `web`. Portals: `/portal/*` (super_admin, school_admin), `/staff/*`, `/parent/*`, `/student/*`.

There is **no Permission model**. Authorization is role + policy. Named strings such as `posts.publish` will be enforced in `PostPolicy` against roles, not a new permissions table.

## Existing admin authorization

Portal pages: `auth` + `role:super_admin,school_admin`.

`PeopleAccessService::administers()` is the correct gate for publishing. Ordinary teachers must not publish public articles.

## Existing frontend routes

`/`, `/about`, `/admissions`, `/contact`, `/nursery`, `/primary`, `/secondary`, `/branches`, `/pta`, `/alumni`.

There is no `/news`, `/privacy`, `/terms`, `/feed`, `/sitemap.xml`, or `/ads.txt` route.

`/{page}` is a closed `whereIn` list. News detail URLs must be registered **before** that catch-all, or they will never match.

## Existing SEO

Almost none. Portal pages send `noindex,nofollow`. Public pages have no canonical, Open Graph, or JSON-LD. One meta description exists on `branches.html`.

## Existing sitemap / robots / RSS

- `public/robots.txt` currently allows everything (`Disallow:` empty). It must be tightened so portals are not crawled, without blocking `/`, `/news`, or images.
- No sitemap.
- No RSS.

## Existing settings

`SchoolSetting` via `GET/PUT /api/v1/school-settings`. No AdSense or publishing flags. A dedicated `publishing_settings` row is justified so school settings stay academic.

## Existing media

Static images: `/site/Image/`. Private uploads: `DocumentService` on the local disk. News featured images: public disk + validation.

## Existing analytics

None. Architecture may record `article_view` later; do not invent view counts or display fake numbers.

## Existing privacy / cookies

No privacy, terms, or consent UI. Session + CSRF cookies only. AdSense/analytics scripts must not load until a real publisher ID exists **and** the visitor has consented where required.

## Existing content management

Internal announcements only. Do not extend `Announcement` for public news.

## Existing tests

Feature tests use `RefreshDatabase`, `RoleSeeder`, and `CreatesAcademicContext` (`$this->admin()`, `$this->actingAs()`). Mirror `FrontendRoutingTest` and announcement API tests.

## Potential conflicts

| Risk | Decision |
|------|----------|
| `announcements` vs public news | Separate domain. Never share tables. |
| `/{page}` catch-all | Register `/news`, `/privacy`, `/terms` first. |
| `AnnouncementCategory::Event` | Internal notice type only. Public Events is a **post category**. |
| Staff publish rights | Portal admins only. |
| `PublicSiteController` | Dead code. Do not revive. Use `FrontendController` + dedicated news controllers. |
| Fake AdSense IDs | Never invent `ca-pub`. Empty config = no ads, no ads.txt line. |

## Files to reuse

- `FrontendController`, `FrontendPage`, `FrontendLinker`
- `ApiResponse`, `PeopleAccessService`, `SchoolSetting`
- Policy + service + FormRequest + Resource pattern
- Admin rail HTML / `admin-command.css` / CSRF fetch pattern
- `CreatesAcademicContext`
- Existing About and Contact pages (already substantial; do not invent awards)

## Files to modify

- `routes/web.php` — news, legal, sitemap, feed, ads.txt (before `/{page}`)
- `routes/api/v1.php` — include news routes
- `routes/console.php` — `site:audit`
- `app/Providers/AppServiceProvider.php` — policies
- `app/Support/FrontendLinker.php` — `/news`, legal pages, portal news
- `public/robots.txt`
- `resources/frontend/public/*.html` — News nav link
- `resources/frontend/admin/dashboard.html` (and sibling rails) — News desk link
- `bootstrap/app.php` — only if a 404 view is needed (Laravel default views)
- `.env.example` — `ADSENSE_*` placeholders, empty
- `tests/Feature/FrontendRoutingTest.php` — news/legal routes if they appear in nav
- `database/seeders/DatabaseSeeder.php`

## Files to create

- This audit
- Migrations for categories, tags, posts, pivot, publishing_settings
- Models, enums, policies, services, requests, resources, controllers
- Admin `news.html` + `portal-news.js`
- Public Blade: news index/show/category/search, privacy, terms, 404
- `AdSlot` view component
- `site:audit` command
- Feature tests under `tests/Feature/News/`

## Implementation notes

- Public URL: `/news` and `/news/{category}/{slug}`
- Search: `/news?q=` with `noindex` on result pages
- Comments stay **off** by default; no comment table until the school asks
- No `post_views` table (no justified analytics store yet)
- No `post_revisions` in the first cut
- Scheduled posts release when `scheduled_at` is due (`PostService::releaseScheduled`)
- AdSense renders only when enabled + valid client id + public page + ads allowed + consent
)
