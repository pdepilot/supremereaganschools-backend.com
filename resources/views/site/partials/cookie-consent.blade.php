<aside class="consent-banner" data-consent-banner hidden role="dialog" aria-labelledby="consent-title" aria-describedby="consent-copy">
  <div class="consent-inner">
    <div class="consent-copy">
      <p class="consent-kicker" id="consent-title">Cookie choices</p>
      <p id="consent-copy">Necessary cookies keep the site working. Analytics and advertising cookies are optional, and the desks never show ads. <a href="/privacy">Privacy Policy</a></p>
    </div>
    <form class="consent-actions" method="post" action="{{ url('/privacy/consent') }}">
      @csrf
      <input type="hidden" name="ads" value="0">
      <input type="hidden" name="analytics" value="0">
      <button type="submit" class="consent-btn ghost">Necessary only</button>
      <button type="submit" class="consent-btn" name="analytics" value="1">Allow analytics</button>
      <button type="submit" class="consent-btn solid" name="ads" value="1" formaction="{{ url('/privacy/consent') }}" data-consent-all>Accept optional</button>
    </form>
  </div>
</aside>
