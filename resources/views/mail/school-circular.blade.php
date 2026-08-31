<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $subjectLine }}</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style>
    :root { color-scheme: light; supported-color-schemes: light; }
    @media only screen and (max-width: 620px) {
      .mail-shell { width: 100% !important; }
      .mail-pad { padding: 24px 18px !important; }
      .mail-title { font-size: 24px !important; line-height: 1.2 !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f4efe4;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
  @php
    $logoSrc = $school['logo_data_uri'] ?? $school['logo_url'] ?? null;
    if (! empty($school['logo_path']) && isset($message)) {
        try {
            $logoSrc = $message->embed($school['logo_path']);
        } catch (\Throwable) {
            $logoSrc = $school['logo_data_uri'] ?? $school['logo_url'] ?? $logoSrc;
        }
    }
  @endphp
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4efe4;">
    {{ $preheader }}
  </div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4efe4;margin:0;padding:0;">
    <tr>
      <td align="center" style="padding:28px 12px 36px;">
        <table role="presentation" class="mail-shell" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;background-color:#fffdf8;border:1px solid #e4d8c2;">
          <tr>
            <td style="height:8px;background-color:#5B2D8B;font-size:0;line-height:0;">&nbsp;</td>
          </tr>
          <tr>
            <td align="center" class="mail-pad" style="padding:32px 42px 20px;background-color:#243A8F;">
              @if ($logoSrc)
                <img src="{{ $logoSrc }}" alt="{{ $school['name'] }} crest" width="88" height="88" style="display:block;margin:0 auto 14px;width:88px;height:88px;border:0;outline:none;border-radius:4px;background:#fffdf8;">
              @endif
              <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:10px;letter-spacing:0.28em;text-transform:uppercase;color:#ffffff;">
                Official circular
              </p>
              <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:28px;line-height:1.15;color:#fffdf8;font-weight:600;">
                {{ $school['name'] }}
              </p>
              @if (! empty($school['motto']))
                <p style="margin:8px 0 0;font-family:Georgia,'Times New Roman',serif;font-size:14px;font-style:italic;color:#ffffff;">
                  {{ $school['motto'] }}
                </p>
              @endif
              @if (! empty($school['founded']))
                <p style="margin:10px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#ffffff;">
                  Established {{ $school['founded'] }}
                </p>
              @endif
            </td>
          </tr>
          <tr>
            <td style="height:4px;background-color:#ffffff;font-size:0;line-height:0;">&nbsp;</td>
          </tr>
          <tr>
            <td class="mail-pad" style="padding:32px 42px 12px;">
              <p style="margin:0 0 10px;font-family:Arial,Helvetica,sans-serif;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;color:#5B2D8B;">
                From the office · {{ $school['short_name'] ?? 'SRS' }}
              </p>
              <h1 class="mail-title" style="margin:0 0 18px;font-family:Georgia,'Times New Roman',serif;font-size:30px;line-height:1.2;font-weight:600;color:#243A8F;">
                {{ $headline }}
              </h1>
              <p style="margin:0 0 18px;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.7;color:#243A8F;">
                {{ $greeting }}
              </p>
              <div style="font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.75;color:#24324a;">
                {!! $bodyHtml !!}
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 42px 28px;">
              <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:15px;line-height:1.6;color:#243A8F;">
                With the compliments of the office,
              </p>
              <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:18px;color:#243A8F;font-weight:600;">
                {{ $school['name'] }}
              </p>
              <p style="margin:4px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#5B2D8B;">
                The school office
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 42px 0;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="height:1px;background-color:#5B2D8B;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="background-color:#243A8F;padding:26px 42px 22px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  @if ($logoSrc)
                    <td valign="top" width="52" style="width:52px;padding-right:14px;">
                      <img src="{{ $logoSrc }}" alt="{{ $school['name'] }} crest" width="44" height="44" style="display:block;width:44px;height:44px;border:0;outline:none;background:#fffdf8;border-radius:3px;">
                    </td>
                  @endif
                  <td valign="top">
                    <p style="margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:10px;letter-spacing:0.2em;text-transform:uppercase;color:#ffffff;">
                      {{ $school['short_name'] ?? 'SRS' }} · School office
                    </p>
                    <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:1.2;color:#fffdf8;font-weight:600;">
                      {{ $school['name'] }}
                    </p>
                    @if (! empty($school['motto']))
                      <p style="margin:6px 0 0;font-family:Georgia,'Times New Roman',serif;font-size:13px;font-style:italic;color:#ffffff;">
                        {{ $school['motto'] }}
                      </p>
                    @endif
                  </td>
                </tr>
              </table>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
                <tr>
                  <td valign="top" width="50%" style="padding-right:12px;">
                    <p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:9px;letter-spacing:0.16em;text-transform:uppercase;color:#ffffff;">Campus</p>
                    @if (! empty($school['address']))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#f4efe4;">{{ $school['address'] }}</p>
                    @endif
                    @if (! empty($school['city_line']))
                      <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#ffffff;">{{ $school['city_line'] }}</p>
                    @endif
                    @foreach ($school['campuses'] ?? [] as $campus)
                      @if (! empty($campus['name']) && ($campus['address'] ?? '') !== ($school['address'] ?? ''))
                        <p style="margin:10px 0 0;font-family:Georgia,'Times New Roman',serif;font-size:12px;line-height:1.5;color:#ffffff;">
                          {{ $campus['name'] }}@if (! empty($campus['address'])) · {{ $campus['address'] }}@endif
                        </p>
                      @endif
                    @endforeach
                  </td>
                  <td valign="top" width="50%" style="padding-left:12px;">
                    <p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:9px;letter-spacing:0.16em;text-transform:uppercase;color:#ffffff;">Correspondence</p>
                    @if (! empty($school['phone']))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#f4efe4;">Tel {{ $school['phone'] }}</p>
                    @endif
                    @if (! empty($school['whatsapp']))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#f4efe4;">
                        WhatsApp
                        @if (! empty($school['whatsapp_url']))
                          <a href="{{ $school['whatsapp_url'] }}" style="color:#ffffff;text-decoration:none;">{{ $school['whatsapp'] }}</a>
                        @else
                          {{ $school['whatsapp'] }}
                        @endif
                      </p>
                    @endif
                    @if (! empty($school['email']))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#f4efe4;">
                        <a href="mailto:{{ $school['email'] }}" style="color:#f4efe4;text-decoration:none;">{{ $school['email'] }}</a>
                      </p>
                    @endif
                    @if (! empty($school['admissions_email']) && $school['admissions_email'] !== ($school['email'] ?? ''))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#ffffff;">
                        Admissions <a href="mailto:{{ $school['admissions_email'] }}" style="color:#ffffff;text-decoration:none;">{{ $school['admissions_email'] }}</a>
                      </p>
                    @endif
                    @if (! empty($school['office_hours']))
                      <p style="margin:0 0 4px;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#ffffff;">{{ $school['office_hours'] }}</p>
                    @endif
                    @if (! empty($school['website']))
                      <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:13px;line-height:1.55;color:#ffffff;">
                        <a href="{{ $school['website'] }}" style="color:#ffffff;text-decoration:none;">{{ $school['website'] }}</a>
                      </p>
                    @endif
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        <p style="margin:16px 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5;color:#8a7b62;max-width:560px;">
          This is an official circular of {{ $school['name'] }}. Please keep it for the house to which it was written.
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
