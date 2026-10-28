<?php
declare(strict_types=1);

namespace RockSoftware\DbSanitizer\Test\TestCase;

use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;

/**
 * The fictional application's schema and a few rows, rebuilt for each test.
 */
trait SchemaTrait
{
    /**
     * @return \Cake\Database\Connection
     */
    protected function connection(): Connection
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = ConnectionManager::get('test');

        return $connection;
    }

    /**
     * @return void
     */
    protected function buildSchema(): void
    {
        $db = $this->connection();
        foreach (['customers', 'orders', 'sessions', 'customer_types', 'app_settings', 'extra'] as $table) {
            $db->execute(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
        $db->execute('DELETE FROM db_sanitizer_runs');
        $db->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY, customer_number VARCHAR(20), name VARCHAR(100),
            email VARCHAR(255), phone VARCHAR(30), street VARCHAR(100), notes TEXT, plate_number VARCHAR(10),
            api_key VARCHAR(64), created DATETIME)');
        $db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER, customer_email VARCHAR(255),
            internal_note TEXT, status VARCHAR(20), total DECIMAL(10,2))');
        $db->execute('CREATE TABLE sessions (id VARCHAR(40) PRIMARY KEY, data TEXT)');
        $db->execute('CREATE TABLE customer_types (id INTEGER PRIMARY KEY, value VARCHAR(20), label VARCHAR(50))');
        $db->execute('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, setting_key VARCHAR(50), value VARCHAR(50))');

        $db->insert('customers', [
            'id' => 1, 'customer_number' => 'C-1', 'name' => 'Alex Example', 'email' => 'alex@example.org',
            'phone' => '+358401111111', 'street' => 'Testikatu 1', 'notes' => 'Prefers mornings.',
            'plate_number' => 'ABC-123', 'api_key' => 'live-key-1',
        ]);
        $db->insert('customers', [
            'id' => 2, 'customer_number' => 'C-2', 'name' => 'Sam Sample', 'email' => 'sam@example.org',
            'phone' => null, 'street' => '', 'notes' => null, 'plate_number' => null, 'api_key' => null,
        ]);
        $db->insert('orders', [
            'id' => 1, 'customer_id' => 1, 'customer_email' => 'alex@example.org',
            'internal_note' => 'Called twice', 'status' => 'open', 'total' => '12.50',
        ]);
        $db->insert('sessions', ['id' => 'abc', 'data' => 'secret session']);
        $db->insert('customer_types', ['id' => 1, 'value' => 'PRIVATE', 'label' => 'Private']);
        $db->insert('app_settings', ['id' => 1, 'setting_key' => 'is_email_enabled', 'value' => '1']);
    }

    /**
     * @param string $sql A query.
     * @return array<int, array<string, mixed>>
     */
    protected function rows(string $sql): array
    {
        return $this->connection()->execute($sql)->fetchAll('assoc');
    }
}
