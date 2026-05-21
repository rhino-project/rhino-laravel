<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\ManifestManager;
use PHPUnit\Framework\TestCase;

class ManifestManagerTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/rhino_manifest_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory (including dotfiles like .blueprint-manifest.json)
        $files = array_merge(
            glob($this->tempDir . '/*'),
            glob($this->tempDir . '/.*')
        );
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_creates_manifest_when_none_exists(): void
    {
        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertNull($manifest['generated_at']);
        $this->assertEmpty($manifest['files']);
    }

    public function test_detects_new_file_as_changed(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $this->assertTrue($manager->hasChanged('contracts.yaml', 'abc123'));
    }

    public function test_detects_unchanged_file(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('contracts.yaml', 'abc123', ['app/Models/Contract.php']);
        $manager->save();

        // Reload from disk
        $manager2 = new ManifestManager($this->tempDir);

        $this->assertFalse($manager2->hasChanged('contracts.yaml', 'abc123'));
    }

    public function test_detects_changed_file(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('contracts.yaml', 'abc123', ['app/Models/Contract.php']);
        $manager->save();

        $manager2 = new ManifestManager($this->tempDir);

        $this->assertTrue($manager2->hasChanged('contracts.yaml', 'different_hash'));
    }

    public function test_records_generation_with_generated_files(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $generatedFiles = [
            'app/Models/Contract.php',
            'app/Policies/ContractPolicy.php',
            'tests/Model/ContractTest.php',
        ];

        $manager->recordGeneration('contracts.yaml', 'abc123', $generatedFiles);

        $this->assertEquals($generatedFiles, $manager->getGeneratedFiles('contracts.yaml'));
    }

    public function test_returns_empty_for_untracked_file(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $this->assertEquals([], $manager->getGeneratedFiles('nonexistent.yaml'));
    }

    public function test_saves_and_loads_manifest(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('contracts.yaml', 'hash1', ['file1.php']);
        $manager->recordGeneration('alerts.yaml', 'hash2', ['file2.php', 'file3.php']);
        $manager->save();

        // Reload from disk
        $manager2 = new ManifestManager($this->tempDir);
        $manifest = $manager2->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertNotNull($manifest['generated_at']);
        $this->assertCount(2, $manifest['files']);
        $this->assertEquals('hash1', $manifest['files']['contracts.yaml']['content_hash']);
        $this->assertEquals('hash2', $manifest['files']['alerts.yaml']['content_hash']);
    }

    public function test_gets_tracked_files(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('contracts.yaml', 'hash1', []);
        $manager->recordGeneration('alerts.yaml', 'hash2', []);

        $tracked = $manager->getTrackedFiles();

        $this->assertContains('contracts.yaml', $tracked);
        $this->assertContains('alerts.yaml', $tracked);
        $this->assertCount(2, $tracked);
    }

    public function test_removes_tracking(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('contracts.yaml', 'hash1', []);
        $manager->recordGeneration('alerts.yaml', 'hash2', []);
        $manager->removeTracking('contracts.yaml');

        $tracked = $manager->getTrackedFiles();

        $this->assertNotContains('contracts.yaml', $tracked);
        $this->assertContains('alerts.yaml', $tracked);
    }

    public function test_handles_corrupted_manifest_file(): void
    {
        // Write invalid JSON to manifest path
        file_put_contents($this->tempDir . '/.blueprint-manifest.json', 'not valid json {{{');

        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        // Should gracefully fallback to empty manifest
        $this->assertEquals(1, $manifest['version']);
        $this->assertEmpty($manifest['files']);
    }

    public function test_manifest_records_generation_timestamp(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $before = date('c');
        $manager->recordGeneration('contracts.yaml', 'hash1', []);
        $after = date('c');

        $manifest = $manager->getManifest();
        $genAt = $manifest['files']['contracts.yaml']['generated_at'];

        $this->assertGreaterThanOrEqual($before, $genAt);
        $this->assertLessThanOrEqual($after, $genAt);
    }
}
