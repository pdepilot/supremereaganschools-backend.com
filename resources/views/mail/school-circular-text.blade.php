{{ $school['name'] }}
{{ $school['motto'] ?? '' }}
@if (! empty($school['founded']))
Established {{ $school['founded'] }}
@endif

Official circular
{{ $headline }}

{{ $greeting }}

{{ $bodyText }}

With the compliments of the office,
{{ $school['name'] }}
The school office

Campus
{{ $school['address'] ?? '' }}
{{ $school['city_line'] ?? '' }}
@foreach ($school['campuses'] ?? [] as $campus)
@if (! empty($campus['name']))
{{ $campus['name'] }}{{ ! empty($campus['address']) ? ' · '.$campus['address'] : '' }}
@endif
@endforeach

Correspondence
@if (! empty($school['phone']))
Tel {{ $school['phone'] }}
@endif
@if (! empty($school['whatsapp']))
WhatsApp {{ $school['whatsapp'] }}
@endif
@if (! empty($school['email']))
{{ $school['email'] }}
@endif
@if (! empty($school['admissions_email']) && $school['admissions_email'] !== ($school['email'] ?? ''))
Admissions {{ $school['admissions_email'] }}
@endif
@if (! empty($school['office_hours']))
{{ $school['office_hours'] }}
@endif
@if (! empty($school['website']))
{{ $school['website'] }}
@endif
