<p>Hello {{ $user->name }},</p>

@if ($suspended)
<p>Your PossibleWords account is currently unavailable. Please contact support if you need assistance.</p>
@else
<p>Your PossibleWords account has been restored and you can sign in again.</p>
@endif
