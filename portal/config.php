<?php
declare(strict_types=1);

const AGENT_SESSION_TIMEOUT = 9 * 60 * 60;

ini_set('session.gc_maxlifetime', (string)AGENT_SESSION_TIMEOUT);
session_start();

const DB_HOST = '192.168.128.151';
const DB_PORT = '5432';
const DB_NAME = 'mydb';
const DB_USER = 'postgres';
const DB_PASS = 'Sum#321';

const APP_NAME = 'Orion IT Services Pvt. Ltd.';
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
