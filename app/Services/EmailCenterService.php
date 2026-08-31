<?php

namespace App\Services;

use App\Enums\EmailAudience;
use App\Enums\OutboundMailStatus;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Mail\SchoolCircularMail;
use App\Models\Campus;
use App\Models\EmailTemplate;
use App\Models\OutboundMail;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailCenterService
{
    public const MAX_RECIPIENTS = 250;

    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @return array<string, mixed>
     */
    public function desk(): array
    {
        $today = OutboundMail::query()->whereDate('sent_at', now()->toDateString());
        $last = OutboundMail::query()->orderByDesc('sent_at')->orderByDesc('id')->first();
        $school = $this->schoolContext();

        return [
            'from' => [
                'address' => (string) config('mail.from.address'),
                'name' => (string) config('mail.from.name'),
                'mailer' => (string) config('mail.default'),
                'host' => (string) config('mail.mailers.smtp.host'),
            ],
            'school' => [
                'name' => $school['name'],
                'motto' => $school['motto'],
            ],
            'metrics' => [
                'sent_today' => (int) (clone $today)->sum('sent_count'),
                'letters_today' => (int) (clone $today)->count(),
                'templates' => EmailTemplate::query()->count(),
                'last_subject' => $last?->subject,
                'last_sent_at' => $last?->sent_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload, User $actor): array
    {
        $this->assertMaySend($actor);

        $recipients = $this->resolveRecipients($payload, $actor);
        $sample = $recipients->first() ?? ['email' => '', 'name' => 'Family'];
        $view = $this->viewData($payload, $sample);

        return [
            'subject' => $view['subjectLine'],
            'recipient_count' => $recipients->count(),
            'sample_name' => $sample['name'],
            'html' => view('mail.school-circular', $view)->render(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload, User $actor): OutboundMail
    {
        $this->assertMaySend($actor);
        $this->assertMailerReady();

        $recipients = $this->resolveRecipients($payload, $actor);
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'audience' => 'No mailbox was found for that audience.',
            ]);
        }

        if ($recipients->count() > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'audience' => 'A single dispatch may reach at most '.self::MAX_RECIPIENTS.' mailboxes.',
            ]);
        }

        $sent = 0;
        $failed = 0;
        $error = null;

        foreach ($recipients as $person) {
            $view = $this->viewData($payload, $person);

            try {
                Mail::to($person['email'])->send(new SchoolCircularMail(
                    subjectLine: $view['subjectLine'],
                    preheader: $view['preheader'],
                    headline: $view['headline'],
                    greeting: $view['greeting'],
                    bodyHtml: $view['bodyHtml'],
                    bodyText: $view['bodyText'],
                    school: $view['school'],
                ));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $error = $e->getMessage();
            }
        }

        $status = match (true) {
            $sent > 0 && $failed === 0 => OutboundMailStatus::Sent,
            $sent > 0 => OutboundMailStatus::Partial,
            default => OutboundMailStatus::Failed,
        };

        if ($status === OutboundMailStatus::Failed) {
            throw ValidationException::withMessages([
                'mail' => $error ?: 'The Hostinger mailbox could not send this circular.',
            ]);
        }

        return OutboundMail::query()->create([
            'email_template_id' => $payload['template_id'] ?? null,
            'subject' => $this->fill((string) $payload['subject'], $this->baseVariables()),
            'audience' => $payload['audience'],
            'body' => $payload['body'],
            'recipient_count' => $recipients->count(),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'status' => $status,
            'error' => $failed > 0 ? $error : null,
            'recipients' => $recipients->pluck('email')->take(50)->values()->all(),
            'sent_by' => $actor->id,
            'sent_at' => now(),
        ])->load(['template', 'sender']);
    }

    public function sendPersonal(User $actor, string $email, string $name, string $subject, string $body): OutboundMail
    {
        $this->assertMaySend($actor);
        $this->assertMailerReady();

        $mailbox = strtolower(trim($email));
        if ($mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'That letter has no mailbox to reply to.',
            ]);
        }

        $person = [
            'email' => $mailbox,
            'name' => trim($name) !== '' ? trim($name) : 'Family',
        ];
        $view = $this->viewData([
            'subject' => $subject,
            'body' => $body,
        ], $person);

        try {
            Mail::to($mailbox)->send(new SchoolCircularMail(
                subjectLine: $view['subjectLine'],
                preheader: $view['preheader'],
                headline: $view['headline'],
                greeting: $view['greeting'],
                bodyHtml: $view['bodyHtml'],
                bodyText: $view['bodyText'],
                school: $view['school'],
            ));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'mail' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'The Hostinger mailbox could not send this reply.',
            ]);
        }

        return OutboundMail::query()->create([
            'email_template_id' => null,
            'subject' => $view['subjectLine'],
            'audience' => EmailAudience::Custom,
            'body' => $body,
            'recipient_count' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'status' => OutboundMailStatus::Sent,
            'error' => null,
            'recipients' => [$mailbox],
            'sent_by' => $actor->id,
            'sent_at' => now(),
        ])->load(['template', 'sender']);
    }

    /**
     * @return list<array{id: int, name: string, email: string, role: string}>
     */
    public function people(User $actor): array
    {
        $this->assertMaySend($actor);

        return User::query()
            ->with('roles')
            ->where('status', UserStatus::Active)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name ?: 'Family',
                'email' => strtolower(trim($user->email)),
                'role' => $user->roles->first()?->name ?: 'On the books',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, array{email: string, name: string}>
     */
    public function resolveRecipients(array $payload, User $actor): Collection
    {
        $audience = EmailAudience::from($payload['audience']);

        if ($audience === EmailAudience::Custom) {
            return $this->parseCustomRecipients((string) ($payload['recipients'] ?? ''));
        }

        if ($audience === EmailAudience::User || $audience === EmailAudience::Users) {
            return $this->peopleByIds($payload['user_ids'] ?? []);
        }

        $query = User::query()
            ->where('status', UserStatus::Active)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('id', '!=', $actor->id)
            ->orderBy('name');

        $roles = match ($audience) {
            EmailAudience::Parents => [RoleSlug::Parent->value],
            EmailAudience::Students => [RoleSlug::Student->value],
            EmailAudience::TeachingStaff => [
                RoleSlug::Teacher->value,
                RoleSlug::Principal->value,
                RoleSlug::VicePrincipal->value,
            ],
            EmailAudience::Staff => [
                RoleSlug::Teacher->value,
                RoleSlug::Staff->value,
                RoleSlug::Principal->value,
                RoleSlug::VicePrincipal->value,
                RoleSlug::Accountant->value,
            ],
            EmailAudience::WholeSchool => null,
            default => null,
        };

        if ($roles !== null) {
            $query->whereHas('roles', fn ($role) => $role->whereIn('slug', $roles));
        }

        return $query->get()
            ->map(fn (User $user) => [
                'email' => strtolower(trim($user->email)),
                'name' => $user->name ?: 'Family',
            ])
            ->unique('email')
            ->values();
    }

    /**
     * @return Collection<int, array{email: string, name: string}>
     */
    private function parseCustomRecipients(string $raw): Collection
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $rows = collect();

        foreach ($parts as $part) {
            $email = strtolower(trim($part));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $local = Str::before($email, '@');
            $rows->push([
                'email' => $email,
                'name' => $this->nameFromLocal($local),
            ]);
        }

        return $rows->unique('email')->values();
    }

    /**
     * @param  list<int|string>|mixed  $ids
     * @return Collection<int, array{email: string, name: string}>
     */
    private function peopleByIds(mixed $ids): Collection
    {
        $wanted = collect(is_array($ids) ? $ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $wanted->all())
            ->where('status', UserStatus::Active)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'email' => strtolower(trim($user->email)),
                'name' => $user->name ?: 'Family',
            ])
            ->unique('email')
            ->values();
    }

    private function nameFromLocal(string $local): string
    {
        $clean = trim(str_replace(['.', '_', '-'], ' ', $local));

        return $clean !== '' ? ucwords($clean) : 'Family';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{email: string, name: string}  $person
     * @return array<string, mixed>
     */
    public function viewData(array $payload, array $person): array
    {
        $school = $this->schoolContext();
        $vars = array_merge($this->baseVariables($school), [
            'name' => $person['name'],
            'email' => $person['email'],
        ]);
        $subject = $this->fill((string) $payload['subject'], $vars);
        $body = $this->fill((string) $payload['body'], $vars);
        $greeting = 'Dear '.$person['name'].',';

        return [
            'subjectLine' => $subject,
            'preheader' => $this->preheader($body, $school),
            'headline' => $subject,
            'greeting' => $greeting,
            'bodyHtml' => $this->letterHtml($body),
            'bodyText' => $body,
            'school' => $school,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $school
     * @return array<string, string>
     */
    public function baseVariables(?array $school = null): array
    {
        $school ??= $this->schoolContext();

        return [
            'school' => $school['name'],
            'motto' => $school['motto'],
            'term' => $school['term'],
            'session' => $school['session'],
            'phone' => $school['phone'],
            'address' => $school['address'],
            'office_email' => $school['email'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schoolContext(): array
    {
        $settings = SchoolSetting::query()
            ->with(['currentAcademicSession', 'currentTerm'])
            ->first();
        $logoPath = $this->logoFile($settings?->logo_path);
        $opens = $this->clockLabel($settings?->office_opens_at);
        $closes = $this->clockLabel($settings?->office_closes_at);
        $website = $this->publicSiteUrl((string) ($settings?->website ?? ''));

        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Campus $campus) => array_filter([
                'name' => $campus->name,
                'address' => $campus->address,
            ]))
            ->values()
            ->all();

        $cityLine = implode(', ', array_filter([
            $settings?->city,
            $settings?->state ? $settings->state.' State' : null,
            ($settings?->city || $settings?->state) ? 'Nigeria' : null,
        ]));

        return [
            'name' => $settings?->name ?: (string) config('app.name'),
            'short_name' => $settings?->short_name ?: 'SRS',
            'motto' => $settings?->motto ?: 'Knowledge · Character · Excellence',
            'address' => (string) ($settings?->address ?? ''),
            'city_line' => $cityLine,
            'phone' => (string) ($settings?->phone ?? ''),
            'whatsapp' => (string) ($settings?->whatsapp ?? ''),
            'whatsapp_url' => $this->whatsappUrl((string) ($settings?->whatsapp ?? '')),
            'email' => (string) ($settings?->email ?: config('mail.from.address')),
            'admissions_email' => (string) ($settings?->admissions_email ?? ''),
            'website' => $website,
            'office_hours' => ($opens && $closes) ? 'Monday – Friday · '.$opens.' – '.$closes : '',
            'founded' => $settings?->founded_on?->format('Y'),
            'term' => (string) ($settings?->currentTerm?->name ?? ''),
            'session' => (string) ($settings?->currentAcademicSession?->name ?? ''),
            'campuses' => $campuses,
            'logo_path' => $logoPath,
            'logo_url' => $this->publicAssetUrl('/site/Image/logo_main.png', $website),
            'logo_data_uri' => $this->logoDataUri($logoPath),
        ];
    }

    private function publicSiteUrl(string $website): string
    {
        $website = trim($website);
        if ($website !== '' && ! $this->isLocalUrl($website)) {
            return rtrim($website, '/');
        }

        $app = rtrim((string) config('app.url'), '/');

        return $this->isLocalUrl($app) ? '' : $app;
    }

    private function publicAssetUrl(string $path, string $website): string
    {
        $base = $website !== '' ? $website : $this->publicSiteUrl('');
        if ($base === '') {
            return '';
        }

        return $base.'/'.ltrim($path, '/');
    }

    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: $url));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    private function logoFile(?string $stored): ?string
    {
        $candidates = [
            is_string($stored) && is_file($stored) ? $stored : null,
            is_string($stored) ? public_path(ltrim(str_replace('\\', '/', $stored), '/')) : null,
            public_path('site/Image/logo_main.png'),
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function logoDataUri(?string $path): ?string
    {
        if (! $path || ! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.jpg') || str_ends_with(strtolower($path), '.jpeg')
            ? 'image/jpeg'
            : 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    private function clockLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $raw = substr((string) $value, 0, 5);
        $parts = explode(':', $raw);
        $hour = (int) ($parts[0] ?? 0);
        $minute = str_pad((string) ($parts[1] ?? '00'), 2, '0', STR_PAD_LEFT);
        $suffix = $hour >= 12 ? 'p.m.' : 'a.m.';
        $hour12 = $hour % 12 ?: 12;

        return $hour12.':'.$minute.' '.$suffix;
    }

    private function whatsappUrl(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        return $digits !== '' ? 'https://wa.me/'.$digits : '';
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function fill(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $match) use ($vars) {
            $key = strtolower($match[1]);

            return array_key_exists($key, $vars) ? $vars[$key] : $match[0];
        }, $text);
    }

    public function letterHtml(string $body): string
    {
        $safe = e($body);
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;

        return nl2br($safe, false);
    }

    /**
     * @param  array<string, mixed>  $school
     */
    private function preheader(string $body, array $school): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        $snippet = mb_substr($plain, 0, 90);

        return $snippet !== '' ? $snippet : (string) $school['name'];
    }

    private function assertMaySend(User $actor): void
    {
        if (! $this->access->administers($actor)) {
            throw ValidationException::withMessages([
                'audience' => 'Only the office may dispatch school email.',
            ]);
        }
    }

    private function assertMailerReady(): void
    {
        if (config('mail.default') !== 'smtp') {
            return;
        }

        $password = config('mail.mailers.smtp.password');
        if (! is_string($password) || $password === '') {
            throw ValidationException::withMessages([
                'mail' => 'Set MAIL_PASSWORD in .env to the Hostinger mailbox password, then dispatch again.',
            ]);
        }
    }
}
