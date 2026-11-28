<?php
// Socket-free integration fixture. Use the production SELECTs unchanged.
class mysqli
{
    private PDO $db;
    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->sqliteCreateFunction('UNIX_TIMESTAMP', 'time');
    }
    public function query($sql)
    {
        $stmt = $this->db->query(str_replace(' FOR UPDATE', '', $sql));
        return new class($stmt) {
            private $rows;
            public $num_rows;
            function __construct($stmt) {
                $this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->num_rows = count($this->rows);
            }
            function fetch_assoc() { return array_shift($this->rows); }
        };
    }
    public function prepare($sql)
    {
        $sql = preg_replace('/ON DUPLICATE KEY UPDATE.*/s',
            'ON CONFLICT(invoice_id) DO UPDATE SET expires_at=MAX(expires_at,excluded.expires_at),demand_json=excluded.demand_json', $sql);
        return new class($this->db->prepare($sql)) {
            private $stmt;
            private $args;
            function __construct($stmt) { $this->stmt = $stmt; }
            function bind_param($types, ...$args) { $this->args = $args; }
            function execute() { return $this->stmt->execute($this->args); }
        };
    }
    function begin_transaction() { return $this->db->beginTransaction(); }
    function commit() { return $this->db->commit(); }
    function rollback() { return $this->db->rollBack(); }
}
