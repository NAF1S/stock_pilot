<x-mail::message>
# Reset your password

Someone requested a password reset for your {{ config('app.name') }} account.

<x-mail::button :url="$resetUrl">
Choose a new password
</x-mail::button>

This link expires in **{{ $expiresMinutes }} minutes** and can only be used once.

If you did not request this, you can safely ignore this email — your password will not change.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
