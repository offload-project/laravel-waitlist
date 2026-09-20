{{-- Sent when somebody is let off the waitlist. --}}
<x-mail::message>
# {{ __('waitlist::notifications.invited.greeting', ['name' => $name]) }}

{{ __('waitlist::notifications.invited.line') }}

{{ __('waitlist::notifications.invited.access_line') }}

<x-mail::button :url="$url">
{{ __('waitlist::notifications.invited.action_text') }}
</x-mail::button>

{{ __('waitlist::notifications.invited.footer') }}
</x-mail::message>
