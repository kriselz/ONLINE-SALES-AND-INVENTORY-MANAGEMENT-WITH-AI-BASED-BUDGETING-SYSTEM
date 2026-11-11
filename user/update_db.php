<?php
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
exit('Online schema execution is disabled. Select your hosting database in phpMyAdmin and import the project-root system.sql file.');
?>