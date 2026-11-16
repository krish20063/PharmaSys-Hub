<?php
/**
 * PharmaSys Hub - Vercel Serverless Function Gateway
 * Routes incoming web requests through the centralized application router.
 */

// Define Vercel execution environment flag
if (!defined('VERCEL_ENVIRONMENT')) {
    define('VERCEL_ENVIRONMENT', true);
}

// Delegate execution to the master router
require __DIR__ . '/../router.php';
