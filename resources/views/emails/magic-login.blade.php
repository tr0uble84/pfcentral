<x-mail::message>
# Sign in to {{ config('brand.name') }}

Click the button below to log in to your account. This link will expire in 15 minutes.

<x-mail::button :url="$url">
Log in securely
</x-mail::button>

If you didn't request this email, you can safely ignore it.

Thanks,<br>
{{ config('brand.name') }}
</x-mail::message>