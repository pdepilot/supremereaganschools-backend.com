@php
  $type = $cta['type']->value;
  $strength = $cta['strength']->value;
@endphp
<aside class="school-cta school-cta-{{ $strength }}" data-cta-type="{{ $type }}" aria-label="Next step">
  <p class="school-cta-kicker">Next step</p>
  <h2>{{ $cta['heading'] }}</h2>
  <p>{{ $cta['body'] }}</p>
  <nav class="school-cta-links">
    @foreach($cta['links'] as $link)
      <a href="{{ $link['href'] }}" data-analytics="article_cta_click" data-cta-target="{{ $link['href'] }}">{{ $link['label'] }}</a>
    @endforeach
  </nav>

  @if(in_array($type, ['admissions', 'contact'], true) && $strength !== 'soft')
    <form class="school-cta-form" data-enquiry-form data-analytics-start="admission_enquiry_started">
      <p class="school-cta-form-lead">Write to the office. The letter stays private.</p>
      <div class="school-cta-fields">
        <label>Your name <input name="name" required maxlength="255" autocomplete="name"></label>
        <label>Email <input type="email" name="email" required maxlength="255" autocomplete="email"></label>
        <label>Phone <input name="phone" required maxlength="40" autocomplete="tel"></label>
        <label>Child’s intended level
          <select name="intended_level">
            <option value="">Choose if you know</option>
            <option value="nursery">Nursery</option>
            <option value="primary">Primary</option>
            <option value="junior_secondary">Junior secondary</option>
            <option value="senior_secondary">Senior secondary</option>
          </select>
        </label>
        <label>Enquiry type
          <select name="enquiry_type">
            <option value="admissions">Admissions</option>
            <option value="visit">Campus visit</option>
            <option value="fees">Fees</option>
            <option value="general">General</option>
          </select>
        </label>
        <label>Message <textarea name="message" required maxlength="5000" rows="3"></textarea></label>
      </div>
      <input type="hidden" name="subject" value="Admission enquiry">
      @if($article)
        <input type="hidden" name="source_post_id" value="{{ $article->id }}">
        <input type="hidden" name="source_url" value="{{ $article->publicUrl() }}">
      @endif
      <p data-enquiry-notice></p>
      <button type="submit">Send the enquiry</button>
    </form>
  @endif
</aside>
