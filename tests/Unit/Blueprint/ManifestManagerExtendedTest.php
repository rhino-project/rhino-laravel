<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\ManifestManager;
use PHPUnit\Framework\TestCase;

class ManifestManagerExtendedTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/rhino_manifest_ext_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = array_merge(
            glob($this->tempDir . '/*') ?: [],
            glob($this->tempDir . '/.*') ?: []
        );
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        // Also clean nested dirs
        $subDirs = glob($this->tempDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($subDirs as $subDir) {
            array_map('unlink', glob($subDir . '/*') ?: []);
            rmdir($subDir);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // save() creates directories when they don't exist
    // ------------------------------------------------------------------

    public function test_save_creates_directory_when_missing(): void
    {
        $nestedDir = $this->tempDir . '/nested/subdir';
        $manager = new ManifestManager($nestedDir);

        $manager->recordGeneration('test.yaml', 'hash', ['file.php']);
        $manager->save();

        $this->assertFileExists($nestedDir . '/.blueprint-manifest.json');
    }

    // ------------------------------------------------------------------
    // getManifest returns full structure
    // ------------------------------------------------------------------

    public function test_get_manifest_includes_all_recorded_data(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('a.yaml', 'hash_a', ['model_a.php']);
        $manager->recordGeneration('b.yaml', 'hash_b', ['model_b.php', 'policy_b.php']);

        $manifest = $manager->getManifest();

        $this->assertArrayHasKey('version', $manifest);
        $this->assertArrayHasKey('files', $manifest);
        $this->assertArrayHasKey('generated_at', $manifest);
        $this->assertArrayHasKey('a.yaml', $manifest['files']);
        $this->assertArrayHasKey('b.yaml', $manifest['files']);

        $this->assertEquals('hash_a', $manifest['files']['a.yaml']['content_hash']);
        $this->assertEquals(['model_a.php'], $manifest['files']['a.yaml']['generated_files']);
        $this->assertArrayHasKey('generated_at', $manifest['files']['a.yaml']);
    }

    // ------------------------------------------------------------------
    // Multiple record/save/load cycles
    // ------------------------------------------------------------------

    public function test_incremental_saves_preserve_all_data(): void
    {
        // First generation
        $manager1 = new ManifestManager($this->tempDir);
        $manager1->recordGeneration('first.yaml', 'hash1', ['file1.php']);
        $manager1->save();

        // Second generation (different process)
        $manager2 = new ManifestManager($this->tempDir);
        $manager2->recordGeneration('second.yaml', 'hash2', ['file2.php']);
        $manager2->save();

        // Verify both are present
        $manager3 = new ManifestManager($this->tempDir);
        $this->assertFalse($manager3->hasChanged('first.yaml', 'hash1'));
        $this->assertFalse($manager3->hasChanged('second.yaml', 'hash2'));
    }

    // ------------------------------------------------------------------
    // removeTracking + save
    // ------------------------------------------------------------------

    public function test_remove_tracking_persists_after_save(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('keep.yaml', 'hash1', ['file1.php']);
        $manager->recordGeneration('remove.yaml', 'hash2', ['file2.php']);
        $manager->removeTracking('remove.yaml');
        $manager->save();

        $manager2 = new ManifestManager($this->tempDir);
        $this->assertContains('keep.yaml', $manager2->getTrackedFiles());
        $this->assertNotContains('remove.yaml', $manager2->getTrackedFiles());
    }

    // ------------------------------------------------------------------
    // getGeneratedFiles for file with no generated_files key
    // ------------------------------------------------------------------

    public function test_get_generated_files_returns_empty_for_missing_key(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $result = $manager->getGeneratedFiles('nonexistent.yaml');

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // Manifest with empty file content
    // ------------------------------------------------------------------

    public function test_handles_empty_manifest_file(): void
    {
        file_put_contents($this->tempDir . '/.blueprint-manifest.json', '');

        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertEmpty($manifest['files']);
    }

    // ------------------------------------------------------------------
    // hasChanged with existing file same hash vs different hash
    // ------------------------------------------------------------------

    public function test_has_changed_returns_false_for_same_hash(): void
    {
        $manager = new ManifestManager($this->tempDir);
        $manager->recordGeneration('file.yaml', 'hash_abc', ['out.php']);

        $this->assertFalse($manager->hasChanged('file.yaml', 'hash_abc'));
    }

    public function test_has_changed_returns_true_for_different_hash(): void
    {
        $manager = new ManifestManager($this->tempDir);
        $manager->recordGeneration('file.yaml', 'hash_abc', ['out.php']);

        $this->assertTrue($manager->hasChanged('file.yaml', 'hash_xyz'));
    }

    // ------------------------------------------------------------------
    // getTrackedFiles when empty
    // ------------------------------------------------------------------

    public function test_get_tracked_files_returns_empty_for_fresh_manifest(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $this->assertSame([], $manager->getTrackedFiles());
    }

    // ------------------------------------------------------------------
    // removeTracking for non-existent file
    // ------------------------------------------------------------------

    public function test_remove_tracking_does_not_fail_for_untracked_file(): void
    {
        $manager = new ManifestManager($this->tempDir);
        $manager->removeTracking('nonexistent.yaml');

        $this->assertSame([], $manager->getTrackedFiles());
    }

    // ------------------------------------------------------------------
    // save updates generated_at timestamp
    // ------------------------------------------------------------------

    public function test_save_sets_generated_at_timestamp(): void
    {
        $manager = new ManifestManager($this->tempDir);
        $manager->recordGeneration('x.yaml', 'hash', ['file.php']);
        $manager->save();

        $manager2 = new ManifestManager($this->tempDir);
        $manifest = $manager2->getManifest();

        $this->assertNotNull($manifest['generated_at']);
    }

    // ------------------------------------------------------------------
    // Manifest with valid JSON but non-array content
    // ------------------------------------------------------------------

    public function test_handles_json_string_manifest(): void
    {
        file_put_contents($this->tempDir . '/.blueprint-manifest.json', '"just a string"');

        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertEmpty($manifest['files']);
    }

    public function test_handles_json_number_manifest(): void
    {
        file_put_contents($this->tempDir . '/.blueprint-manifest.json', '42');

        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertEmpty($manifest['files']);
    }

    // ------------------------------------------------------------------
    // Record overwriting previous generation
    // ------------------------------------------------------------------

    public function test_record_generation_overwrites_previous(): void
    {
        $manager = new ManifestManager($this->tempDir);

        $manager->recordGeneration('file.yaml', 'old_hash', ['old.php']);
        $manager->recordGeneration('file.yaml', 'new_hash', ['new.php']);

        $this->assertSame('new_hash', $manager->getManifest()['files']['file.yaml']['content_hash']);
        $this->assertSame(['new.php'], $manager->getGeneratedFiles('file.yaml'));
    }
}
