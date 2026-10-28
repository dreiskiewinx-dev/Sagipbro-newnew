<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
exit('Public registration is disabled. Contact the barangay administrator for access.');
