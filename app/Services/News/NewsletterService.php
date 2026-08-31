<?php

namespace App\Services\News;

use App\Models\NewsletterSubscriber;
use Illuminate\Validation\ValidationException;

class NewsletterService
{
    public function subscribe(string $email, bool $consented, ?string $source = null): NewsletterSubscriber
    {
        if (! $consented) {
            throw ValidationException::withMessages([
                'consent' => 'A subscription needs a clear yes. We will not add an address without it.',
            ]);
        }

        $email = strtolower(trim($email));

        $existing = NewsletterSubscriber::query()->where('email', $email)->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update([
                    'status' => 'active',
                    'consented_at' => now(),
                    'source' => $source,
                ]);
            }

            return $existing->fresh() ?? $existing;
        }

        return NewsletterSubscriber::query()->create([
            'email' => $email,
            'consented_at' => now(),
            'status' => 'active',
            'source' => $source,
        ]);
    }
}
