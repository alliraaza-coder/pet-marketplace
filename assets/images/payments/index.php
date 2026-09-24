<?php
/**
 * Secure .htaccess blocker for payment proof uploads
 * Prevents execution of PHP files inside this directory
 */
header('HTTP/1.0 403 Forbidden');
exit('No direct access allowed.');
