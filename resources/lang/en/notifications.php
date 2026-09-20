<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Verify Waitlist Email
    |--------------------------------------------------------------------------
    |
    | Sent when somebody signs up, to confirm they can read the address they
    | gave. Publish with `--tag=waitlist-lang` to change the words, and
    | `--tag=waitlist-views` to change the shape.
    |
    */

    'verify' => [
        'subject' => 'Verify Your Email Address',
        'greeting' => 'Hello :name!',
        'line' => 'Please verify your email address to confirm your spot on the waitlist.',
        'action_text' => 'Verify Email',
        'footer' => 'If you did not sign up for this waitlist, you can ignore this email.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Waitlist Invited
    |--------------------------------------------------------------------------
    |
    | Sent when somebody is let off the waitlist and into the application.
    |
    */

    'invited' => [
        'subject' => "You're Invited!",
        'greeting' => 'Hello :name!',
        'line' => 'Great news! You have been invited from our waitlist.',
        'access_line' => 'You can now access our application and start using all the features.',
        'action_text' => 'Get Started',
        'footer' => 'Thank you for your patience!',
    ],
];
