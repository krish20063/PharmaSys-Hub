<?php
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
die("403 Forbidden: Direct access to configuration assets is strictly forbidden.");

