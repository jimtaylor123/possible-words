<h1>{{ $digest ? 'Your notification digest' : 'New notification' }}</h1>
@foreach ($notifications as $notification)
<p><strong>{{ $notification['title'] }}</strong><br>{{ $notification['body'] }}</p>
@if (($notification['kind'] ?? null) === 'new_word_batch' && is_array($notification['words'] ?? null))
<ul>
@foreach ($notification['words'] as $word)
<li><a href="{{ $word['url'] }}">{{ $word['text'] }}</a> (.com {{ $word['domain_status'] }})</li>
@endforeach
</ul>
@endif
@endforeach
<p><a href="{{ route('account.show') }}">Manage notification email preferences</a></p>
