{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Supreme Reagan Schools — News &amp; Insights</title>
    <link>{{ url('/news') }}</link>
    <atom:link href="{{ url('/feed') }}" rel="self" type="application/rss+xml" />
    <description>Public articles from Supreme Reagan Schools.</description>
    <language>en</language>
    @foreach($articles as $article)
      <item>
        <title>{{ $article->title }}</title>
        <link>{{ $article->publicUrl() }}</link>
        <guid isPermaLink="true">{{ $article->publicUrl() }}</guid>
        <pubDate>{{ $article->published_at?->toRfc2822String() }}</pubDate>
        <author>{{ $article->authorName() }}</author>
        <description><![CDATA[{{ $article->excerpt }}]]></description>
        @if($article->featuredImageUrl())
          <media:content url="{{ $article->featuredImageUrl() }}" medium="image" />
        @endif
      </item>
    @endforeach
  </channel>
</rss>
