<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fee Receipt {{ $reference }} · {{ $school['name'] }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Cormorant+Garamond:wght@500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #0d1b2a;
      --navy: #243a8f;
      --deep: #1a2d73;
      --gold: #b08d57;
      --paper: #fbf8f2;
      --line: rgba(36, 58, 143, 0.14);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: {{ $forEmail ? '#eef1f8' : '#e8ecf6' }};
      color: var(--ink);
      font-family: "Montserrat", Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
    }
    .sheet {
      width: min(720px, 100%);
      margin: {{ $forEmail ? '0 auto' : '28px auto' }};
      background:
        radial-gradient(circle at 100% 0%, rgba(176, 141, 87, 0.12), transparent 42%),
        linear-gradient(180deg, #ffffff 0%, var(--paper) 100%);
      border: 1px solid rgba(36, 58, 143, 0.12);
      box-shadow: {{ $forEmail ? 'none' : '0 28px 70px rgba(13, 27, 42, 0.12)' }};
      overflow: hidden;
    }
    .hero {
      position: relative;
      padding: 2.1rem 2rem 1.7rem;
      background:
        linear-gradient(135deg, rgba(13, 27, 42, 0.28), rgba(26, 45, 115, 0.55)),
        linear-gradient(120deg, var(--deep), var(--navy));
      color: #fff;
    }
    .hero-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.4rem;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 0.9rem;
      min-width: 0;
    }
    .brand img {
      width: 64px;
      height: 64px;
      object-fit: contain;
      background: rgba(255,255,255,0.95);
      border-radius: 50%;
      padding: 0.35rem;
    }
    .brand strong {
      display: block;
      font-size: 1.15rem;
      letter-spacing: -0.02em;
      line-height: 1.15;
    }
    .brand span {
      display: block;
      margin-top: 0.25rem;
      font-size: 0.68rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      opacity: 0.86;
    }
    .seal {
      border: 1px solid rgba(255,255,255,0.35);
      padding: 0.55rem 0.75rem;
      font-size: 0.62rem;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      text-align: center;
      white-space: nowrap;
    }
    .hero h1 {
      margin: 0;
      font-family: "Cormorant Garamond", Georgia, serif;
      font-size: clamp(2.2rem, 5vw, 3.2rem);
      font-weight: 600;
      letter-spacing: -0.03em;
      line-height: 0.95;
    }
    .hero p.lead {
      margin: 0.7rem 0 0;
      max-width: 28ch;
      font-size: 0.92rem;
      line-height: 1.5;
      color: rgba(255,255,255,0.88);
    }
    .amount-band {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      align-items: end;
      padding: 1.35rem 2rem;
      border-bottom: 1px solid var(--line);
      background: rgba(255,255,255,0.72);
    }
    .amount-band em {
      display: block;
      font-style: normal;
      font-size: 0.68rem;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--navy);
      margin-bottom: 0.35rem;
    }
    .amount-band strong {
      display: block;
      font-family: "Cormorant Garamond", Georgia, serif;
      font-size: clamp(2.4rem, 6vw, 3.4rem);
      font-weight: 600;
      letter-spacing: -0.03em;
      color: var(--ink);
      line-height: 1;
    }
    .amount-band small {
      display: block;
      margin-top: 0.45rem;
      max-width: 28ch;
      color: rgba(13,27,42,0.62);
      font-size: 0.78rem;
      line-height: 1.4;
    }
    .status-pill {
      align-self: center;
      border: 1px solid rgba(36,58,143,0.25);
      color: var(--navy);
      padding: 0.55rem 0.85rem;
      font-size: 0.68rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .body {
      padding: 1.6rem 2rem 2rem;
    }
    .meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.9rem 1.25rem;
      margin: 0 0 1.5rem;
    }
    .meta div {
      border-top: 1px solid var(--line);
      padding-top: 0.55rem;
    }
    .meta span {
      display: block;
      font-size: 0.62rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(36,58,143,0.78);
      margin-bottom: 0.28rem;
    }
    .meta strong {
      display: block;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--ink);
      line-height: 1.35;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin: 0 0 1.35rem;
    }
    caption {
      caption-side: top;
      text-align: left;
      font-size: 0.68rem;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      color: var(--navy);
      margin-bottom: 0.65rem;
      font-weight: 600;
    }
    th, td {
      padding: 0.75rem 0;
      border-bottom: 1px solid var(--line);
      text-align: left;
      font-size: 0.9rem;
      vertical-align: top;
    }
    th {
      font-size: 0.62rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(13,27,42,0.55);
      font-weight: 600;
    }
    td:last-child, th:last-child { text-align: right; white-space: nowrap; }
    .balance {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      padding: 1rem 0 0;
      border-top: 2px solid var(--navy);
    }
    .balance span {
      font-size: 0.68rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: rgba(13,27,42,0.58);
    }
    .balance strong {
      font-family: "Cormorant Garamond", Georgia, serif;
      font-size: 1.7rem;
      font-weight: 600;
      color: var(--navy);
    }
    .thanks {
      margin: 1.6rem 0 0;
      padding: 1.15rem 1.2rem;
      background: linear-gradient(135deg, rgba(36,58,143,0.08), rgba(176,141,87,0.12));
      border-left: 3px solid var(--gold);
    }
    .thanks p {
      margin: 0;
      font-family: "Cormorant Garamond", Georgia, serif;
      font-size: 1.35rem;
      line-height: 1.35;
      color: var(--ink);
    }
    .thanks small {
      display: block;
      margin-top: 0.45rem;
      font-family: "Montserrat", Arial, sans-serif;
      font-size: 0.78rem;
      color: rgba(13,27,42,0.62);
      line-height: 1.5;
    }
    .foot {
      padding: 1.1rem 2rem 1.4rem;
      border-top: 1px solid var(--line);
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      color: rgba(13,27,42,0.62);
      font-size: 0.72rem;
      line-height: 1.55;
    }
    .actions {
      width: min(720px, calc(100% - 2rem));
      margin: 0 auto 2rem;
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem;
      justify-content: center;
    }
    .actions a, .actions button {
      appearance: none;
      border: 1px solid var(--navy);
      background: var(--navy);
      color: #fff;
      min-height: 2.7rem;
      padding: 0.65rem 1.1rem;
      font: inherit;
      font-size: 0.72rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      text-decoration: none;
      cursor: pointer;
    }
    .actions a.ghost, .actions button.ghost {
      background: transparent;
      color: var(--navy);
    }
    @media (max-width: 640px) {
      .hero, .amount-band, .body, .foot { padding-left: 1.2rem; padding-right: 1.2rem; }
      .meta { grid-template-columns: 1fr; }
      .hero-top, .amount-band, .foot, .balance { flex-direction: column; align-items: flex-start; }
      .brand img { width: 52px; height: 52px; }
    }
    @media print {
      body { background: #fff; }
      .sheet { margin: 0; width: 100%; box-shadow: none; border: 0; }
      .actions { display: none !important; }
    }
  </style>
</head>
<body>
  @unless($forEmail)
  <div class="actions no-print">
    <button type="button" onclick="window.print()">Print receipt</button>
    <a class="ghost" href="javascript:window.close()">Close</a>
  </div>
  @endunless

  <article class="sheet" aria-label="Official fee receipt">
    <header class="hero">
      <div class="hero-top">
        <div class="brand">
          <img src="{{ $school['logo'] }}" alt="{{ $school['name'] }}">
          <div>
            <strong>{{ $school['name'] }}</strong>
            <span>{{ $school['motto'] }}</span>
          </div>
        </div>
        <div class="seal">Official<br>Fee Receipt</div>
      </div>
      <h1>Payment received</h1>
      <p class="lead">A clear record of trust — for the family that grows with this house.</p>
    </header>

    <section class="amount-band">
      <div>
        <em>Amount paid</em>
        <strong>{{ $amount }}</strong>
        <small>{{ $amount_words }}</small>
      </div>
      <div class="status-pill">{{ $status }}</div>
    </section>

    <div class="body">
      <div class="meta">
        <div><span>Receipt no.</span><strong>{{ $reference }}</strong></div>
        <div><span>Paid on</span><strong>{{ $paid_on }}@if($paid_at)<br><small style="font-weight:500;color:rgba(13,27,42,.55)">{{ $paid_at }} WAT</small>@endif</strong></div>
        <div><span>Pupil</span><strong>{{ $student_name }}</strong></div>
        <div><span>Admission no.</span><strong>{{ $admission_number }}</strong></div>
        <div><span>Class / form</span><strong>{{ $form }}</strong></div>
        <div><span>Channel</span><strong>{{ $channel }}</strong></div>
        <div><span>Invoice</span><strong>{{ $invoice_number }}</strong></div>
        <div><span>Term</span><strong>{{ $term }}</strong></div>
      </div>

      <table>
        <caption>Applied to</caption>
        <thead>
          <tr>
            <th>Description</th>
            <th>Amount</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($allocations as $line)
            <tr>
              <td>{{ $line['description'] }}</td>
              <td>{{ $line['amount'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @if ($note !== '')
        <p style="margin:0 0 1.2rem;font-size:.88rem;color:rgba(13,27,42,.7)"><strong>Note:</strong> {{ $note }}</p>
      @endif

      @if ($balance !== null)
        <div class="balance">
          <span>{{ $balance_clear ? 'Invoice balance' : 'Balance remaining' }}</span>
          <strong>{{ $balance_clear ? 'Cleared' : $balance }}</strong>
        </div>
      @endif

      <div class="thanks">
        <p>Thank you for walking with {{ $school['name'] }}.</p>
        <small>This receipt was sealed by the fees desk @if($recorded_by)({{ $recorded_by }})@endif. Keep it for your records — and for the quiet pride of a house well kept.</small>
      </div>
    </div>

    <footer class="foot">
      <div>
        {!! $school['address_html'] !!}<br>
        {{ $school['phone'] }} · {{ $school['email'] }}
      </div>
      <div style="text-align:right">
        {{ $school['motto'] }}<br>
        {{ $school['url'] }}
      </div>
    </footer>
  </article>
</body>
</html>
