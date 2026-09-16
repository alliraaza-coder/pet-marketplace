<?php
// Prevent directory listing in uploads
header('HTTP/1.0 403 Forbidden');
exit('No direct access allowed.');
