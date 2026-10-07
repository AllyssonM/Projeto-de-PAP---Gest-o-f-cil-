<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'gestao_facil';
const DB_USER = 'root';
const DB_PASS = '';

require_once __DIR__ . '/app.php';

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $connection = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // O relógio do MySQL (NOW(), CURRENT_TIMESTAMP) passa a ser o da aplicação (config/app.php). Sem isto, se o servidor de base de dados
        // estiver noutro fuso, as horas escritas pelo PHP e pelo MySQL ficariam trocadas (ex.: uma entrada às 09:39 apareceria às 08:39).
        $connection->exec("SET time_zone = '" . (new DateTimeImmutable('now', app_timezone()))->format('P') . "'");
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'Unknown database')) {
            throw new RuntimeException('A base de dados gestao_facil ainda não foi criada. Execute instalar_base_dados.bat ou importe database/gestao_facil.sql no phpMyAdmin.');
        }
        throw $error;
    }
    return $connection;
}
?>
