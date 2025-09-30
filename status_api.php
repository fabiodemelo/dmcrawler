<?php
// status_api.php
// This API endpoint checks for the existence of .running lock files
// created by your background scripts to determine their status.

header('Content-Type: application/json');

$status = [];
// IMPORTANT: Adjust this base directory if your demelos project is installed elsewhere
$base_dir = __DIR__ . '/'; // This assumes status_api.php is in the root of your demelos project

$scripts_to_monitor = [
    'crawler' => 'crawler.php',        // For "Run Crawler (Domains)" button
    'geturls' => 'getURLS.php',        // For "Get URLs" button
    'getemails' => 'crawler.php',      // For "Get Emails" button (assuming it also runs crawler.php)
    'addtomautic' => 'addtomautic.php' // For "Send Emails to Mautic" button
];

foreach ($scripts_to_monitor as $key => $script_filename) {
    // Generate a unique lock file name for each *logical* task, even if they share an underlying script.
    // For "Get Emails", we'll make its lock file 'getemails.running' to differentiate its status
    // from the general 'crawler.running' status, even if both call 'crawler.php'.
    $lock_file_name = str_replace('.php', '', $script_filename) . '.' . $key . '.running';

    // Special handling for the main 'crawler' process to use 'crawler.running'
    if ($key === 'crawler' && $script_filename === 'crawler.php') {
        $lock_file_name = 'crawler.running';
    } elseif ($key === 'getemails' && $script_filename === 'crawler.php') {
        // If 'getemails' is truly a distinct invocation of 'crawler.php' that you want to monitor separately,
        // it needs a distinct lock file name, e.g., 'crawler.getemails.running'.
        // Otherwise, if it's just another way to say 'run the crawler', you might use 'crawler.running'.
        // For distinct monitoring, we'll assign a distinct lock name here.
        $lock_file_name = 'crawler.getemails.running'; // Unique lock for the "Get Emails" task
    }


    $lock_file_path = $base_dir . $lock_file_name;

    if (file_exists($lock_file_path)) {
        $started_at = @file_get_contents($lock_file_path); // Get the content (timestamp)
        $status[$key] = [
            'running' => true,
            'started_at' => $started_at ? trim($started_at) : 'unknown time'
        ];
    } else {
        $status[$key] = ['running' => false];
    }
}

echo json_encode($status);
?>