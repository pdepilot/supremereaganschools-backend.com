<aside class="ad-slot" data-ad-position="{{ $position }}" aria-label="Advertisement">
  <p class="ad-slot-label">Advertisement</p>
  @if(!empty($auto) && !empty($client))
    <ins class="adsbygoogle"
         style="display:block"
         data-ad-client="{{ $client }}"
         data-ad-format="auto"
         data-full-width-responsive="true"></ins>
    <script>
      (adsbygoogle = window.adsbygoogle || []).push({});
    </script>
  @endif
</aside>
