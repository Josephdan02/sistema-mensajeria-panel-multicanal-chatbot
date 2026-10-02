<?php

$files = [
    'app/Services/Chatbot/ChatbotService.php',
    'app/Services/Response/ResponseGenerator.php',
    'app/Http/Controllers/ChatController.php',
    'app/Services/Chatbot/ConversationManager.php',
];

foreach ($files as $file) {
    $content = file_get_contents(__DIR__.'/'.$file);
    $validUtf8 = mb_check_encoding($content, 'UTF-8');
    $countC3 = substr_count($content, "\xC3");
    $countE9 = substr_count($content, "\xE9");
    echo $file.' valid_utf8='.var_export($validUtf8, true).' 0xC3='.$countC3.' 0xE9='.$countE9.PHP_EOL;
}
