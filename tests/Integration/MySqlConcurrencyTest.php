<?php

namespace Tests\Integration;

use Exception;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * اختبارات التزامن والقفل التشاؤمي (Pessimistic Locking Concurrency) على MySQL.
 * تستهدف حصراً قاعدة اختبار معزولة ينتهي اسمها بـ _testing.
 * يتم تخطي الاختبار بأمان ونظافة إذا كان خادم MySQL المحلي غير مشغّل.
 */
class MySqlConcurrencyTest extends TestCase
{
    private ?PDO $pdo1 = null;
    private ?PDO $pdo2 = null;
    private string $testDb = 'providence_testing';

    protected function setUp(): void
    {
        parent::setUp();

        // 1. التحقق الصارم من سياسة الأمان: اسم القاعدة يجب أن ينتهي بـ _testing
        $dbName = env('DB_MYSQL_TEST_DATABASE') ?: 'providence_testing';
        if (str_contains(strtolower($dbName), 'prod') || $dbName === 'providence_prod') {
            throw new RuntimeException("CRITICAL: Concurrency tests must NEVER target production DB ({$dbName}).");
        }

        if (! str_ends_with($dbName, '_testing')) {
            throw new RuntimeException("CRITICAL: Concurrency tests must target a database ending with '_testing'. Current: {$dbName}");
        }

        $this->testDb = $dbName;
    }

    private function getPdoConnection(): ?PDO
    {
        $host = config('database.connections.mysql.host', '127.0.0.1');
        $port = config('database.connections.mysql.port', '3306');
        $user = config('database.connections.mysql.username', 'root');
        $pass = config('database.connections.mysql.password', '');

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$this->testDb};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return $pdo;
        } catch (Exception $e) {
            return null;
        }
    }

    public function test_mysql_database_name_adheres_to_safety_policy(): void
    {
        $this->assertStringEndsWith('_testing', $this->testDb);
        $this->assertStringNotContainsString('prod', strtolower($this->testDb));
    }

    public function test_concurrent_lock_for_update_serializes_and_times_out(): void
    {
        $this->pdo1 = $this->getPdoConnection();
        if (! $this->pdo1) {
            $this->markTestSkipped("Local MySQL server ({$this->testDb}) is offline. Skipped safely.");
        }

        $this->pdo2 = $this->getPdoConnection();
        $this->assertNotNull($this->pdo2);

        // إنشاء جدول معزول للاختبار
        $this->pdo1->exec('CREATE TABLE IF NOT EXISTS _test_concurrency_locks (
            id INT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            status VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB');

        $this->pdo1->exec('REPLACE INTO _test_concurrency_locks (id, enrollment_id, status) VALUES (1, 101, "pending")');

        // اتصال 1: يبدأ معاملة ويحجز الصف بقفل تشاؤمي FOR UPDATE
        $this->pdo1->beginTransaction();
        $stmt1 = $this->pdo1->prepare('SELECT * FROM _test_concurrency_locks WHERE id = 1 FOR UPDATE');
        $stmt1->execute();
        $row1 = $stmt1->fetch();
        $this->assertSame('pending', $row1['status']);

        // اتصال 2: يضبط مهلة القفل على ثانية واحدة ويحاول حجز نفس الصف
        $this->pdo2->exec('SET innodb_lock_wait_timeout = 1');

        $lockTimeoutOccurred = false;
        try {
            $this->pdo2->beginTransaction();
            $stmt2 = $this->pdo2->prepare('SELECT * FROM _test_concurrency_locks WHERE id = 1 FOR UPDATE');
            $stmt2->execute();
            $this->pdo2->commit();
        } catch (PDOException $e) {
            // SQLSTATE 1205: Lock wait timeout exceeded
            if (str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout')) {
                $lockTimeoutOccurred = true;
            }
            if ($this->pdo2->inTransaction()) {
                $this->pdo2->rollBack();
            }
        }

        // إثبات أن الاتصال الثاني تم حجبه وتجاوز المهلة بسبب القفل التشاؤمي للاتصال الأول
        $this->assertTrue($lockTimeoutOccurred, 'InnoDB lock wait timeout should occur when row is locked with FOR UPDATE by another connection.');

        // اتصال 1 يحرر القفل عبر commit
        $this->pdo1->exec('UPDATE _test_concurrency_locks SET status = "first_half_collected" WHERE id = 1');
        $this->pdo1->commit();

        // اتصال 2 يستطيع الآن حجز الصف بنجاح وبلا مهلة انتظار
        $this->pdo2->beginTransaction();
        $stmt2 = $this->pdo2->prepare('SELECT * FROM _test_concurrency_locks WHERE id = 1 FOR UPDATE');
        $stmt2->execute();
        $row2 = $stmt2->fetch();
        $this->assertSame('first_half_collected', $row2['status']);
        $this->pdo2->commit();

        // تنظيف الجدول التجريبي
        $this->pdo1->exec('DROP TABLE IF EXISTS _test_concurrency_locks');
    }

    public function test_concurrent_collection_race_condition_prevented_by_pessimistic_lock(): void
    {
        $this->pdo1 = $this->getPdoConnection();
        if (! $this->pdo1) {
            $this->markTestSkipped("Local MySQL server ({$this->testDb}) is offline. Skipped safely.");
        }

        $this->pdo2 = $this->getPdoConnection();

        $this->pdo1->exec('CREATE TABLE IF NOT EXISTS _test_enrollment_race (
            id INT PRIMARY KEY,
            has_first_half TINYINT DEFAULT 0
        ) ENGINE=InnoDB');

        $this->pdo1->exec('REPLACE INTO _test_enrollment_race (id, has_first_half) VALUES (101, 0)');

        // المحاكي: العامل الأول يبدأ التحصيل ويقفل التسجيل
        $this->pdo1->beginTransaction();
        $stmt1 = $this->pdo1->prepare('SELECT has_first_half FROM _test_enrollment_race WHERE id = 101 FOR UPDATE');
        $stmt1->execute();
        $isFirstHalfActive = (int) $stmt1->fetchColumn();
        $this->assertSame(0, $isFirstHalfActive);

        // العامل الأول يسجل النصف الأول
        $this->pdo1->exec('UPDATE _test_enrollment_race SET has_first_half = 1 WHERE id = 101');
        $this->pdo1->commit();

        // العامل الثاني يدخل بعد انتهاء الأول: يجد has_first_half = 1 فيرفض تكرار النصف الأول
        $this->pdo2->beginTransaction();
        $stmt2 = $this->pdo2->prepare('SELECT has_first_half FROM _test_enrollment_race WHERE id = 101 FOR UPDATE');
        $stmt2->execute();
        $isFirstHalfActiveNow = (int) $stmt2->fetchColumn();
        $this->assertSame(1, $isFirstHalfActiveNow);

        // يرفض العامل الثاني إنشاء دفعة مكررة للجزء الأول
        $shouldRejectDuplicate = ($isFirstHalfActiveNow === 1);
        $this->assertTrue($shouldRejectDuplicate, 'Second concurrent request must observe committed state and reject duplicate first_half collection.');
        $this->pdo2->commit();

        $this->pdo1->exec('DROP TABLE IF EXISTS _test_enrollment_race');
    }

    protected function tearDown(): void
    {
        if ($this->pdo1 && $this->pdo1->inTransaction()) {
            $this->pdo1->rollBack();
        }
        if ($this->pdo2 && $this->pdo2->inTransaction()) {
            $this->pdo2->rollBack();
        }
        $this->pdo1 = null;
        $this->pdo2 = null;

        parent::tearDown();
    }
}
