<?php

// API error messages (English). User-facing; never include internal details.
return [
    'UNAUTHENTICATED' => 'You are not signed in or your session has expired.',
    'MFA_REQUIRED' => 'Two-factor verification is required.',
    'FORBIDDEN' => 'You do not have permission to perform this action.',
    'NOT_FOUND' => 'The requested resource was not found.',
    'VERSION_CONFLICT' => 'This record was changed by another action. Please reload.',
    'IDEMPOTENCY_CONFLICT' => 'This idempotency key was already used with a different request.',
    'CAPACITY_EXCEEDED' => 'The capacity limit has been reached.',
    'INVALID_STATE' => 'This action is not allowed in the current state.',
    'IDENTITY_REPLAYED' => 'This identity assertion was already used. Please obtain a new one.',
    'NEW_CONVERSATION_REQUIRED' => 'This conversation can no longer be reopened. Please start a new one.',
    'CURSOR_EXPIRED' => 'The event cursor has expired. Please reload the conversation.',
    'VALIDATION_FAILED' => 'The given data was invalid.',
    'CAPABILITY_UNSUPPORTED' => 'The selected service or model does not support this capability.',
    'PAYLOAD_TOO_LARGE' => 'The request payload is too large.',
    'RATE_LIMITED' => 'Too many requests. Please try again later.',
    'TEMPORARILY_UNAVAILABLE' => 'The service is temporarily unavailable. Please try again later.',
    'INTERNAL_ERROR' => 'An unexpected error occurred. Please try again later.',
    'CSRF_MISMATCH' => 'The security token has expired. Please reload the page.',
];
