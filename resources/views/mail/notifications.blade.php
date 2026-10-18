<h1>{{ $digest ? 'Your notification digest' : 'New notification' }}</h1>
@foreach ($notifications as $notification)
<p><strong>{{ $notification['title'] }}</strong><br>{{ $notification['body'] }}</p>
@endforeach
<p><a href="{{ route('account.show') }}">Manage notification email preferences</a></p>
