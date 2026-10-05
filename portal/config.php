<?php
declare(strict_types=1);

session_start();

const DB_HOST = '192.168.158.121';
const DB_PORT = '5432';
const DB_NAME = 'mydb';
const DB_USER = 'postgres';
const DB_PASS = 'Sum#321';

const APP_NAME = 'Orion IT Services Pvt. Ltd.';
const IDLE_SESSION_TIMEOUT = 300; // seconds without heartbeat before session is considered stale

date_default_timezone_set('Asia/Kolkata');

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
