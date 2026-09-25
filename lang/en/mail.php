<?php

// System email copy (English). Emails contain summaries only, never full conversations or secrets.
return [
    'greeting' => 'Hello :name,',
    'password_reset' => [
        'subject' => ':app password reset',
        'body' => 'We received a request to reset your password. Open the link below within :minutes minutes to set a new password:',
        'ignore' => 'If you did not request this, you can ignore this email. Your password will not change.',
    ],
    'invitation' => [
        'subject' => 'You are invited to join :workspace',
        'body' => ':inviter invited you to join the ":workspace" support team. Open the link below within :hours hours to accept:',
        'ignore' => 'If you do not recognise the sender, you can ignore this email.',
    ],
];
