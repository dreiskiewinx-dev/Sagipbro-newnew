<?php

// Accounts are provisioned by an authenticated administrator, never publicly.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
exit('Public registration is disabled. Contact the barangay administrator for access.');
