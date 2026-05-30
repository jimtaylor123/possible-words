<x-mail::message>
# Welcome to PossibleWords!

Hi {{ $user->name }},

Thanks for joining PossibleWords! You can now browse generated words, suggest definitions, and vote on them.

<x-mail::button :url="route('home')">
Start Exploring
</x-mail::button>

Happy word hunting!

Thanks,<br>
The PossibleWords Team
</x-mail::message>
