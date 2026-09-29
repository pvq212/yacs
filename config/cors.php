<?php

// Widget 使用受限 bearer token；staff cookie 不允許跨站 credentials。
return ['paths' => ['api/v1/widget/*', 'uploads/*'], 'allowed_methods' => ['GET', 'POST', 'PUT', 'OPTIONS'], 'allowed_origins' => ['*'], 'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Authorization', 'Idempotency-Key', 'Accept', 'Accept-Language'], 'exposed_headers' => ['Retry-After'], 'max_age' => 600, 'supports_credentials' => false];
