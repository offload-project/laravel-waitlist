{{--
    The waitlist verification email.

    Publish with `php artisan vendor:publish --tag=waitlist-views` to change the
    layout; the wording lives in the translations, publishable separately with
    `--tag=waitlist-lang`.
--}}
<x-mail::message>
# {{ __('waitlist::notifications.verify.greeting', ['name' => $name]) }}

{{ __('waitlist::notifications.verify.line') }}

<x-mail::button :url="$url">
{{ __('waitlist::notifications.verify.action_text') }}
</x-mail::button>

{{ __('waitlist::notifications.verify.footer') }}
</x-mail::message>
