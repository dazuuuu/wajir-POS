<?php

// app/config/mail.php — shop-computer install: email is off (it needs the
// internet). Fill these in only if this computer has internet and you want
// password-reset emails.

return [
    'host'       => '',
    'port'       => 587,
    'encryption' => 'tls',
    'username'   => '',
    'password'   => '',
    'from_email' => '',
    'from_name'  => 'Shop POS',
    'skip_verify' => false,
];
