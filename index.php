<?php

require 'src/client.php';
$config = require 'config/tokens.php';

$syncManager = new SyncManager(
    $config['leader_token'], 
    $config['participant_tokens']
);
$syncManager->run();
