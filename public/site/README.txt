# Frontend serving layout

- `public/site/` — public marketing pages, CSS, JS, and images (same-origin static assets).
- `resources/frontend/` — login HTML and admin portal HTML served by Laravel after authentication.
- Admin HTML is intentionally not placed in `public/site/admin/` so `php artisan serve` cannot bypass auth by serving those files as static files.

Open the school site at `/` or `/site/index.html`. The portal login is `/portal/login`. After sign-in, the command desk is `/portal/home`. Desk pages are at `/site/portal/{page}.html`.
