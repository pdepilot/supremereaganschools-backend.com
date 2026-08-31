# Education & Parent Resource Hub

Supreme Reagan Schools treats **News & Insights** as a first-class public desk: a long-term education resource, a parent information hub, and a calm path into the school. It is not a generic blog and it is not an advertisement farm.

The intended journey is:

**Search → useful article → parent resource → the school → academics → admissions → enquiry → application.**

Help first. Monetize only appropriate public pages, later, and never ahead of trust.

## What already existed

The previous News & Insights phase already provided posts, categories, tags, publishing workflow, SEO fields, related articles, legal pages, sitemap, RSS, robots, ads.txt, consent, and AdSense configuration. This phase **extends** that system. It does not replace it.

## Content architecture

Public writing still lives in `posts`. Categories and tags remain expandable. Editors may add new categories; the code does not freeze today’s list.

Seeded categories now include school news, education, parenting, student life, academic resources, admissions, events, school community, student development, and examinations.

### Content types

Stored on the existing post as `content_type`:

- `article`
- `guide`
- `resource`
- `announcement`
- `event`
- `admission_update`

Every type uses the same publishing, SEO, author, category, tag, image, and visibility rules.

### Resource hubs

Hubs are database rows (`resource_hubs`), not hardcoded doorways.

| Hub | URL |
| --- | --- |
| Parent Resources | `/resources/parenting` |
| Study & Academic Success | `/resources/study-tips` |
| Examination Preparation | `/resources/examination-preparation` |
| Student Development | `/resources/student-development` |

Also:

- `/resources` — hub index
- `/news` — the journal
- `/admissions` — the existing school admissions page (not replaced)

A hub is **indexed only when it has at least two published public notes**. Empty hubs stay `noindex`. They are not created to harvest search traffic.

## Topic clusters (editorial planning)

Planning fields on a post (admin only, not printed as keywords):

- `pillar_topic`
- `supporting_topic`
- `audience` — parents, students, teachers, general
- `educational_level` — early years, primary, junior secondary, senior secondary, all
- `intent` — informational, educational, admissions, school information

Example clusters the desk can grow over years:

1. **Parenting** → choosing a school → questions to ask → transition → Supreme Reagan admissions  
2. **Examination preparation** → habits → revision → time → WAEC → academic support at the house  
3. **Student development** → leadership → confidence → communication → student life at the house  

Do not stuff these labels onto the public page.

## CTA strategy

Reusable component: `<x-school-cta type="admissions" />` with strength `none | soft | standard | strong`.

Default: **auto destination** from category / content type / intent, strength **standard**.

Examples:

- Parenting → Parent resources  
- School selection / admissions intent → Admissions  
- Study / examinations → Academics  
- Student life → Student life  
- School news → About  

Do not use one aggressive CTA on every article. `none` is allowed when the note should simply teach.

Admissions and contact CTAs at standard/strong strength may include a **private enquiry form** (name, email, phone, intended level, type, message). Letters are stored on the existing contact desk. They are not public.

## SEO strategy

- Unique title, description, canonical, and heading structure on indexable pages  
- Article + BreadcrumbList + EducationalOrganization structured data where they fit  
- XML sitemap, robots.txt, RSS  
- Search result pages, empty archives, drafts, previews, portals, and login stay out of the index  
- Evergreen notes show **Published** and, when materially updated, **Updated**. Dates are not moved to look new  

Quality is usefulness, structure, authorship, and honesty — not a 1,500-word rule.

## AdSense strategy

Ads are a later layer, not the purpose of the site.

Order of priority:

1. Useful content  
2. Parent experience  
3. School credibility  
4. Search visibility  
5. Admissions  
6. Monetization  

Ads may appear only on eligible **public** pages, with page-level `ads_enabled` and `child_directed` controls. They never appear on portals, login, fees, results, applications, or authenticated sessions.

Publisher IDs are configuration. Nothing is invented. Approval remains Google’s decision.

## Editorial workflow

1. Draft original writing for a real parent or pupil question  
2. Assign type, category, tags, audience, and pillar (planning)  
3. Complete the quality checklist (title, introduction, headings, image alt, SEO, CTA)  
4. Human review — AI may assist; it must not publish unreviewed bulk copy  
5. Schedule or publish  
6. Review again when `review_due_at` arrives. The system does **not** republish old notes automatically  

## Publishing workflow

Statuses remain `draft → review → scheduled → published → archived`.

Only school administrators publish. Featured / pinned notes can appear on the homepage journal and hubs.

Resource PDFs may be attached when the file is a real guide. Do not upload empty PDFs to manufacture pages.

## Privacy

Public forms, cookies, analytics, advertising, retention, and rights are described on `/privacy`. Newsletter signup requires an explicit yes. Enquiry personal data is never exposed on public APIs or article pages.

## What the school must still write

Architecture does not replace authorship. Hubs stay thin until the house publishes original guidance. Do not scrape, spin, or mass-generate articles.
