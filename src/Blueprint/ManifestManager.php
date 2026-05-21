<?php

namespace Rhino\Blueprint;

class ManifestManager
{
    protected string $manifestPath;
    protected array $manifest;

    public function __construct(string $blueprintsDir)
    {
        $this->manifestPath = rtrim($blueprintsDir, '/') . '/.blueprint-manifest.json';
        $this->manifest = $this->load();
    }

    /**
     * Check if a blueprint file has changed since last generation.
     *
     * @param string $filename The blueprint filename (e.g., 'contracts.yaml')
     * @param string $currentHash The current file hash
     * @return bool True if file has changed or is new
     */
    public function hasChanged(string $filename, string $currentHash): bool
    {
        if (!isset($this->manifest['files'][$filename])) {
            return true; // New file
        }

        return $this->manifest['files'][$filename]['content_hash'] !== $currentHash;
    }

    /**
     * Record that a blueprint file was processed.
     *
     * @param string $filename The blueprint filename
     * @param string $contentHash The file's content hash
     * @param array $generatedFiles List of generated file paths
     */
    public function recordGeneration(string $filename, string $contentHash, array $generatedFiles): void
    {
        $this->manifest['files'][$filename] = [
            'content_hash' => $contentHash,
            'generated_files' => $generatedFiles,
            'generated_at' => date('c'),
        ];

        $this->manifest['generated_at'] = date('c');
    }

    /**
     * Get the list of files generated from a specific blueprint.
     *
     * @param string $filename The blueprint filename
     * @return array List of generated file paths
     */
    public function getGeneratedFiles(string $filename): array
    {
        return $this->manifest['files'][$filename]['generated_files'] ?? [];
    }

    /**
     * Get all tracked blueprint filenames.
     *
     * @return array List of blueprint filenames
     */
    public function getTrackedFiles(): array
    {
        return array_keys($this->manifest['files'] ?? []);
    }

    /**
     * Remove a blueprint from tracking (when the source file is deleted).
     */
    public function removeTracking(string $filename): void
    {
        unset($this->manifest['files'][$filename]);
    }

    /**
     * Save the manifest to disk.
     */
    public function save(): void
    {
        $dir = dirname($this->manifestPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->manifestPath, $json . "\n");
    }

    /**
     * Load the manifest from disk.
     */
    protected function load(): array
    {
        if (!file_exists($this->manifestPath)) {
            return [
                'version' => 1,
                'generated_at' => null,
                'files' => [],
            ];
        }

        $content = file_get_contents($this->manifestPath);

        if ($content === false) {
            return [
                'version' => 1,
                'generated_at' => null,
                'files' => [],
            ];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [
                'version' => 1,
                'generated_at' => null,
                'files' => [],
            ];
        }

        return $data;
    }

    /**
     * Get the full manifest data (for testing/debugging).
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }
}
